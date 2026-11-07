"""Session-Steuerung: Zustimmung, Aufnahme, Analyse, Takt und Posting.

Läuft in einem eigenen asyncio-Thread (zusammen mit dem Discord-Bot). Die GUI
ruft die Methoden thread-sicher über `Controller.submit` auf.
"""
from __future__ import annotations

import asyncio
import logging
import threading
import time
from collections import deque
from enum import Enum
from pathlib import Path
from typing import Any, Callable

import numpy as np
from PIL import Image

from .analysis import CandidatePool, FrameAnalyzer, FrameHistory
from .audio import AudioMonitor, LoopbackRecorder, SampleAudio
from .captions import CaptionService
from .capture.sample import FileSource, SampleCallSource, SampleClock, sample_audio_levels
from .config import Settings
from .posting import DiscordPoster, LocalPoster, TestChannelRequired
from .producer import Meme, MemeProducer
from .scheduler import BlockScheduler
from .state import StateStore

log = logging.getLogger(__name__)
RESUME_MAX_AGE_S = 6 * 3600


class Phase(str, Enum):
    IDLE = "idle"
    AWAITING = "awaiting"     # wartet auf Zustimmung aller
    RUNNING = "running"
    PAUSED = "paused"         # neue Person / Neustart – wartet auf Zustimmung


PHASE_LABEL = {
    Phase.IDLE: "■ Gestoppt",
    Phase.AWAITING: "⏳ Warte auf Zustimmung",
    Phase.RUNNING: "● Aufnahme läuft",
    Phase.PAUSED: "⏸ Pausiert – warte auf Zustimmung",
}


class Controller:
    def __init__(self, settings: Settings, store: StateStore | None = None):
        self.settings = settings
        name = "state-test.json" if settings.test_mode else "state.json"
        self.store = store or StateStore(settings.data_dir / name)
        self.loop: asyncio.AbstractEventLoop | None = None
        self.bot = None
        self.phase = Phase.IDLE
        self.logs: deque[str] = deque(maxlen=300)
        self.on_log: Callable[[str], None] | None = None

        prefs = self.store.prefs
        self.style = prefs.caption_style if prefs.caption_style in {"brainrot", "gsw", "de"} else settings.caption_style
        self.caption_mode = prefs.caption_mode or settings.caption_mode
        self.mic_enabled = prefs.mic_enabled or settings.mic_enabled

        self.pool = CandidatePool()
        self.analyzer = FrameAnalyzer()
        self.history = FrameHistory()
        self.audio = AudioMonitor()
        self.captions = self._make_captions()
        self.producer = MemeProducer(self.captions, style=lambda: self.style,
                                     transcribe_enabled=settings.transcribe, whisper_model=settings.whisper_model)
        self.scheduler: BlockScheduler | None = None
        self.poster = None

        # Zustimmung
        self.participants: dict[int, str] = {}
        self.consented: set[int] = set()
        self.consent_message = None

        # Aufnahme
        self.recording = threading.Event()
        self._closing = threading.Event()
        self._source = None
        self._source_lock = threading.Lock()
        self.window_hwnd: int | None = None
        self.window_title = prefs.window_title or ""
        self.capture_kind = "sample" if settings.test_mode else (prefs.capture_source or settings.capture_source)
        self._audio_rec = None
        self.preview: Image.Image | None = None
        self.preview_lock = threading.Lock()
        self.capture_note = ""
        self.last_meme: Meme | None = None
        self.last_meme_block = 0
        self.blocks_posted = 0
        self.sample_clock = SampleClock()
        self._capture_thread = threading.Thread(target=self._capture_loop, name="qc-capture", daemon=True)
        self._capture_thread.start()
        self.log("Quarter Clown bereit" + (" – TESTMODUS (30-s-Takt, Beispielmaterial)" if settings.test_mode else ""))

    # ---------------------------------------------------------------- misc
    def _make_captions(self) -> CaptionService:
        return CaptionService(mode=self.caption_mode, api_key=self.settings.anthropic_api_key,
                              model=self.settings.anthropic_model, effort=self.settings.anthropic_effort)

    def log(self, msg: str) -> None:
        line = time.strftime("%H:%M:%S ") + msg
        self.logs.append(line)
        log.info(msg)
        if self.on_log:
            try:
                self.on_log(line)
            except Exception:
                pass

    def submit(self, coro) -> "asyncio.Future[Any] | None":
        """Thread-sicher aus der GUI aufrufen."""
        if self.loop is None:
            return None
        return asyncio.run_coroutine_threadsafe(coro, self.loop)

    @property
    def session_active(self) -> bool:
        return self.phase != Phase.IDLE

    @property
    def target_channel_id(self) -> int | None:
        g = self.store.guild
        if self.settings.test_mode:
            return g.test_channel_id or self.settings.test_channel_id
        return g.text_channel_id

    def target_label(self) -> str:
        g = self.store.guild
        if self.settings.test_mode:
            if self.bot is not None and self.target_channel_id:
                name = g.test_channel_name or str(self.target_channel_id)
                return f"#{name} (Testkanal)"
            return "Nur lokal (Ordner output/test) – kein Testkanal gesetzt"
        if g.text_channel_id:
            return f"#{g.text_channel_name} · {g.guild_name}"
        return ""

    def set_style(self, style: str) -> None:
        self.style = style if style in {"brainrot", "gsw", "de"} else "brainrot"
        self.store.prefs.caption_style = self.style
        self.store.save()

    def set_caption_mode(self, mode: str) -> None:
        self.caption_mode = mode if mode in {"auto", "claude", "local"} else "auto"
        self.captions = self._make_captions()
        self.producer.captions = self.captions
        self.store.prefs.caption_mode = self.caption_mode
        self.store.save()

    def set_mic(self, enabled: bool) -> None:
        self.mic_enabled = enabled
        self.store.prefs.mic_enabled = enabled
        self.store.save()
        if self.recording.is_set():
            self._stop_audio()
            self._start_audio()

    def set_source(self, kind: str, hwnd: int | None = None, title: str = "") -> None:
        if self.settings.test_mode:
            return
        with self._source_lock:
            if self._source is not None:
                self._source.close()
            self._source = None
        self.capture_kind = kind
        self.window_hwnd = hwnd
        self.window_title = title
        self.store.prefs.capture_source = kind
        self.store.prefs.window_title = title
        self.store.save()

    # ------------------------------------------------------------- status
    def status(self) -> dict:
        countdown = None
        next_block = None
        if self.scheduler is not None and not self.scheduler.stopped:
            next_block = self.scheduler.current_block or self.scheduler.next_block()
            countdown = max(0.0, self.scheduler.deadline(next_block) - time.time())
        return {
            "phase": self.phase.value,
            "phase_label": PHASE_LABEL[self.phase],
            "recording": self.recording.is_set(),
            "countdown": countdown,
            "next_block": next_block,
            "target": self.target_label(),
            "participants": [{"id": pid, "name": n, "ok": pid in self.consented} for pid, n in self.participants.items()],
            "test_mode": self.settings.test_mode,
            "bot_ready": bool(self.bot is not None and self.bot.is_ready()),
            "invite": getattr(self.bot, "invite", "") if self.bot is not None else "",
            "ai": "Claude (" + self.settings.anthropic_model + ")" if self.captions.ai_enabled else "Lokale Vorlagen",
            "ai_error": self.captions.last_error,
            "capture_note": self.capture_note,
            "blocks_posted": self.blocks_posted,
            "pool_best": self.pool.best_score(),
            "interval": self.settings.timing.interval,
        }

    # ------------------------------------------------------- bot events
    async def on_bot_ready(self) -> None:
        s = self.store.session
        if self.settings.test_mode or not s.active:
            if s.active:
                self.store.stop_session("Neustart (Testmodus)")
            return
        if time.time() - s.t0 > RESUME_MAX_AGE_S:
            self.store.stop_session("Neustart – Session zu alt")
            return
        # Nach Absturz/Neustart: Takt fortsetzen, aber pausiert bis zur erneuten Zustimmung.
        self.log(f"Session {s.id} nach Neustart gefunden – Takt läuft weiter, Aufnahme pausiert")
        self.participants = self.bot.voice_members() if self.bot else {}
        self.consented = set()
        self.phase = Phase.PAUSED
        self._start_scheduler(s.id, s.t0)
        await self._post_consent("🤡⏰ Quarter Clown ist zurück", note="Neustart: Bitte erneut zustimmen. "
                                 "Bereits gepostete Memes werden nicht wiederholt, verpasste nicht nachgeschickt.")

    def on_consent(self, user_id: int, name: str) -> str:
        if self.phase == Phase.IDLE:
            return "Gerade läuft keine Anfrage."
        if self.participants and user_id not in self.participants:
            return "Zustimmen können nur Leute, die gerade im Voice-Channel sind."
        self.participants.setdefault(user_id, name)
        self.consented.add(user_id)
        self.log(f"Zustimmung: {name}")
        asyncio.get_running_loop().create_task(self._after_consent_change())
        return "✅ Danke! Deine Zustimmung ist gespeichert."

    async def on_decline(self, user_id: int, name: str) -> str:
        if self.phase == Phase.IDLE:
            return "Gerade läuft keine Anfrage."
        if self.participants and user_id not in self.participants:
            return "Nur Leute im Voice-Channel können ablehnen."
        await self.stop(f"{name} ist nicht einverstanden")
        return "Okay – nichts wird aufgenommen."

    async def on_roster_change(self, joined: tuple[int, str] | None = None, left: tuple[int, str] | None = None) -> None:
        if self.phase == Phase.IDLE:
            return
        self.participants = self.bot.voice_members() if self.bot else self.participants
        if joined and joined[0] not in self.consented:
            self.participants.setdefault(joined[0], joined[1])
            if self.phase == Phase.RUNNING:
                self._pause(f"{joined[1]} ist dazugekommen")
            self.log(f"{joined[1]} ist dazugekommen – Zustimmung nötig")
            await self._post_consent(f"👋 {joined[1]} ist neu im Call",
                                     note="Aufnahme pausiert, bis alle zugestimmt haben.", mention=joined[0])
        elif left:
            self.log(f"{left[1]} hat den Call verlassen")
        await self._after_consent_change()

    def _all_consented(self) -> bool:
        return bool(self.participants) and set(self.participants) <= self.consented

    async def _after_consent_change(self) -> None:
        await self._update_consent_message()
        if self.phase == Phase.AWAITING and self._all_consented():
            await self._confirm_start()
        elif self.phase == Phase.PAUSED and self._all_consented():
            self._resume()
            if self.bot:
                await self.bot.say(self.target_channel_id, "▶️ Alle haben zugestimmt – Aufnahme läuft weiter.")
        elif self.phase == Phase.RUNNING and not self.participants:
            self._pause("niemand mehr im Voice-Channel")

    async def _post_consent(self, title: str, note: str = "", mention: int | None = None) -> None:
        if self.bot is None:
            return
        from .bot import ConsentView

        embed = self.bot.consent_embed(title, self.participants, self.consented, note)
        text = f"<@{mention}>" if mention else " ".join(f"<@{p}>" for p in self.participants if p not in self.consented)
        self.consent_message = await self.bot.say(self.target_channel_id, text, embed=embed, view=ConsentView(self.bot))

    async def _update_consent_message(self) -> None:
        if self.bot is None or self.consent_message is None:
            return
        try:
            embed = self.consent_message.embeds[0] if self.consent_message.embeds else None
            title = embed.title if embed else "Quarter Clown"
            new = self.bot.consent_embed(title, self.participants, self.consented,
                                         "Alle haben zugestimmt – Aufnahme läuft." if self._all_consented() else "")
            await self.consent_message.edit(embed=new)
        except Exception as exc:
            self.log(f"Zustimmungsnachricht nicht aktualisierbar: {exc}")

    # ------------------------------------------------------------ actions
    async def request_start(self) -> str:
        if self.phase != Phase.IDLE:
            return "Läuft bereits."
        if self.settings.test_mode:
            try:
                self.poster = self._make_poster()
            except TestChannelRequired as exc:
                return str(exc)
            self.participants, self.consented = {}, set()
            await self._confirm_start(test=True)
            return "Testmodus gestartet."
        if self.bot is None or not self.bot.is_ready():
            return "Discord-Bot ist nicht verbunden. Prüfe DISCORD_BOT_TOKEN in .env."
        g = self.store.guild
        if not (g.voice_channel_id and g.text_channel_id):
            return "Bitte zuerst in Discord /quarter setup ausführen."
        if self.capture_kind == "window" and not self.window_hwnd:
            return "Bitte zuerst das Discord-Fenster auswählen."
        self.participants = self.bot.voice_members()
        if not self.participants:
            return f"Niemand im Voice-Channel „{g.voice_channel_name}“."
        self.poster = self._make_poster()
        self.consented = set()
        self.phase = Phase.AWAITING
        self.log("Start angefragt – warte auf Zustimmung aller im Call")
        await self._post_consent("🤡⏰ Quarter Clown möchte diese Session aufnehmen")
        return "Anfrage im Textkanal gepostet. Start, sobald alle zugestimmt haben."

    def _make_poster(self):
        interval = self.settings.timing.interval
        save_dir = self.settings.output_dir / "posted" if self.settings.save_posted else None
        if self.settings.test_mode:
            test_ch = self.store.guild.test_channel_id or self.settings.test_channel_id
            if self.bot is not None and self.bot.is_ready() and test_ch:
                return DiscordPoster(self.bot, test_ch, interval, True, test_channel_id=test_ch,
                                     save_dir=self.settings.output_dir / "test")
            return LocalPoster(self.settings.output_dir / "test", interval)
        return DiscordPoster(self.bot, self.store.guild.text_channel_id, interval, False, save_dir=save_dir)

    async def _confirm_start(self, test: bool = False) -> None:
        t0 = time.time()
        timing = self.settings.timing
        rec = self.store.start_session(t0, timing.interval, self.settings.test_mode, self.target_channel_id)
        self.pool.rotate()
        self.phase = Phase.RUNNING
        self._start_recording()
        self._start_scheduler(rec.id, t0)
        mins = int(timing.interval // 60) or timing.interval
        unit = "Minuten" if timing.interval >= 60 else "Sekunden"
        self.log(f"Session {rec.id} bestätigt – erster Drop in {fmt(timing.interval)}")
        if self.bot is not None and self.target_channel_id and (not test or isinstance(self.poster, DiscordPoster)):
            await self.bot.say(self.target_channel_id,
                               f"🔴 **Aufnahme läuft.** Diese Session wird aufgenommen und automatisch zu Memes verarbeitet. "
                               f"Alle {mins if unit == 'Minuten' else int(timing.interval)} {unit} kommt genau ein Meme. "
                               f"Stoppen: ⛔-Button oder `/quarter stop`." + (" *(Testmodus mit Beispielmaterial)*" if test else ""))

    def _start_scheduler(self, session_id: str, t0: float) -> None:
        if self.poster is None:
            self.poster = self._make_poster()
        self.scheduler = BlockScheduler(session_id=session_id, t0=t0, timing=self.settings.timing, pool=self.pool,
                                        producer=self.producer, poster=self.poster, store=self.store,
                                        on_event=self._on_sched_event)
        self.scheduler.start()

    def _on_sched_event(self, kind: str, data: dict) -> None:
        b = data.get("block")
        if kind == "prepared":
            m: Meme = data["meme"]
            self.log(f"Drop #{b} vorbereitet ({m.kind}, {m.layout}, {'Claude' if m.used_ai else 'Vorlage'}"
                     + (f" – {m.note}" if m.note else "") + ")")
        elif kind == "refined":
            self.log(f"Drop #{b}: besserer Moment kurz vor Schluss übernommen")
        elif kind == "posted":
            m = data["meme"]
            self.last_meme, self.last_meme_block = m, b
            self.blocks_posted += 1
            self.log(f"✅ Drop #{b} gepostet: {m.caption.top} / {m.caption.bottom}".rstrip(" /"))
        elif kind == "rotated":
            self.log(f"Block #{b} abgeschlossen – {data['dropped']} Kandidaten verworfen")
        elif kind == "skipped":
            self.log(f"Drop #{b} übersprungen ({data.get('reason')}) – wird nicht nachgeschickt")
        elif kind == "post_failed":
            self.log(f"Posten von #{b} fehlgeschlagen: {data.get('error')} – neuer Versuch")

    def _pause(self, reason: str) -> None:
        self.phase = Phase.PAUSED
        self.recording.clear()
        self._stop_audio()
        self.analyzer.reset()
        self.history.clear()
        self.log(f"⏸ Aufnahme pausiert: {reason}")

    def _resume(self) -> None:
        self.phase = Phase.RUNNING
        self._start_recording()
        self.log("▶ Aufnahme fortgesetzt")

    async def stop(self, reason: str = "Stopp") -> None:
        was = self.phase
        if self.scheduler is not None:
            self.scheduler.stop()          # ab hier wird nichts mehr gepostet
        self.recording.clear()
        self._stop_audio()
        self.store.stop_session(reason)
        self.phase = Phase.IDLE
        dropped = self.pool.rotate()
        self.history.clear()
        self.audio.clear()
        self.analyzer.reset()
        with self.preview_lock:
            self.preview = None
        self.participants, self.consented = {}, set()
        self.log(f"■ Gestoppt: {reason} ({dropped} Kandidaten gelöscht)")
        if was != Phase.IDLE and self.bot is not None and self.target_channel_id and (
                not self.settings.test_mode or isinstance(self.poster, DiscordPoster)):
            await self.bot.say(self.target_channel_id, f"⛔ **Aufnahme beendet** ({reason}). Es werden keine weiteren Memes gepostet.")
        self.scheduler = None
        self.consent_message = None

    async def shutdown(self) -> None:
        if self.phase != Phase.IDLE:
            await self.stop("App beendet")
        self._closing.set()

    # ------------------------------------------------------------ capture
    def _make_source(self):
        s = self.settings
        if s.test_mode:
            if s.sample_path and Path(s.sample_path).exists():
                return FileSource(s.sample_path, s.capture_fps)
            return SampleCallSource(self.sample_clock)
        if self.capture_kind == "obs":
            from .capture.obs import ObsCapture

            return ObsCapture(s.obs_host, s.obs_port, s.obs_password, s.obs_source)
        if self.window_hwnd:
            from .capture.windows import WindowCapture

            return WindowCapture(self.window_hwnd, self.window_title)
        return None

    def snapshot_once(self) -> Image.Image | None:
        """Einzelbild zur Kontrolle der Fensterauswahl – wird nicht gespeichert/analysiert."""
        try:
            src = self._make_source()
            frame = src.grab() if src else None
            if src:
                src.close()
        except Exception as exc:
            self.capture_note = f"Quelle nicht verfügbar: {exc}"
            return None
        if frame is None:
            self.capture_note = "Kein Bild – Fenster minimiert oder nicht gefunden?"
            return None
        self.capture_note = ""
        img = Image.fromarray(frame)
        img.thumbnail((960, 540))
        return img

    def _start_recording(self) -> None:
        self.analyzer.reset()
        self.recording.set()
        self._start_audio()

    def _start_audio(self) -> None:
        if self.settings.test_mode:
            self._audio_rec = SampleAudio(self.audio, sample_audio_levels(self.sample_clock))
        elif self.settings.audio_enabled:
            self._audio_rec = LoopbackRecorder(self.audio, self.settings.audio_device, self.mic_enabled, on_error=self.log)
        else:
            return
        self._audio_rec.start()

    def _stop_audio(self) -> None:
        if self._audio_rec is not None:
            self._audio_rec.stop()
            self._audio_rec = None
        self.audio.clear()

    def _capture_loop(self) -> None:
        fps = max(1.0, min(10.0, self.settings.capture_fps))
        while not self._closing.is_set():
            if not self.recording.is_set():
                with self._source_lock:
                    if self._source is not None:
                        self._source.close()
                        self._source = None
                time.sleep(0.1)
                continue
            started = time.time()
            try:
                with self._source_lock:
                    if self._source is None:
                        self._source = self._make_source()
                    src = self._source
                frame = src.grab() if src else None
            except Exception as exc:
                frame = None
                self.capture_note = f"Aufnahmefehler: {exc}"
                with self._source_lock:
                    self._source = None
            if frame is None:
                if not self.capture_note:
                    self.capture_note = "Kein Bild – Discord-Fenster sichtbar und nicht minimiert?"
                time.sleep(0.5)
                continue
            self.capture_note = ""
            try:
                self._process(frame, started)
            except Exception as exc:  # Analysefehler dürfen den Takt nie stoppen
                log.exception("Analyse fehlgeschlagen: %s", exc)
            time.sleep(max(0.0, 1.0 / fps - (time.time() - started)))

    def _process(self, frame: np.ndarray, t: float) -> None:
        feats = self.audio.features() if self._audio_rec is not None else None
        moment = self.analyzer.analyze(frame, t, feats)
        small = _downscale(frame, 960)
        if not self.recording.is_set():  # Pause/Stop während der Analyse
            return
        want_c, want_f = self.pool.wants(moment)
        if want_c or want_f:
            trigger = self.history.before(t, 2.5)
            audio = self.audio.recent(8.0) if self.settings.transcribe else None
            self.pool.offer(moment, frame, trigger, audio)
        self.history.push(t, small)
        prev = Image.fromarray(_downscale(frame, 640))
        with self.preview_lock:
            self.preview = prev


def _downscale(frame: np.ndarray, width: int) -> np.ndarray:
    if frame.shape[1] <= width:
        return frame
    h = round(frame.shape[0] * width / frame.shape[1])
    try:
        import cv2  # type: ignore

        return cv2.resize(frame, (width, h), interpolation=cv2.INTER_AREA)
    except Exception:
        return np.asarray(Image.fromarray(frame).resize((width, h)))


def fmt(sec: float) -> str:
    sec = int(round(sec))
    return f"{sec // 60}:{sec % 60:02d}"
