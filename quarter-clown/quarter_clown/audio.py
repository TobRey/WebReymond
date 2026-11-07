"""Audioerfassung und Lach-/Lautstärke-Signale.

Windows: WASAPI-Loopback des Ausgabegeräts (dort, wo Discord ausgibt) über die
Bibliothek `soundcard`. Das eigene Mikrofon kann optional dazugemischt werden.
Nur die letzten ~30 Sekunden liegen im Arbeitsspeicher – es wird nichts auf die
Festplatte geschrieben.
"""
from __future__ import annotations

import logging
import threading
import time
from collections import deque
from dataclasses import dataclass
from typing import Callable

import numpy as np

log = logging.getLogger(__name__)
SR = 16_000
CHUNK = 800  # 50 ms


@dataclass
class AudioFeatures:
    rms: float = 0.0
    loud: float = 0.0    # 0..1 – Ausbruch über dem gleitenden Grundpegel
    laugh: float = 0.0   # 0..1 – rhythmische Energie-Bursts (3–7 Hz), typisch für Lachen


class AudioMonitor:
    """Hält einen Ringpuffer und berechnet Merkmale. Quelle wird per `feed` befüllt."""

    def __init__(self, keep_seconds: float = 30.0):
        self._buf: deque[np.ndarray] = deque(maxlen=int(keep_seconds * SR / CHUNK))
        self._lock = threading.Lock()
        self.base_rms = 0.01
        self.active = False

    def feed(self, samples: np.ndarray) -> None:
        samples = np.asarray(samples, dtype=np.float32).reshape(-1)
        with self._lock:
            for i in range(0, len(samples), CHUNK):
                part = samples[i:i + CHUNK]
                if len(part):
                    self._buf.append(part)

    def recent(self, seconds: float) -> np.ndarray:
        n = max(1, int(seconds * SR / CHUNK))
        with self._lock:
            parts = list(self._buf)[-n:]
        return np.concatenate(parts) if parts else np.zeros(0, dtype=np.float32)

    def clear(self) -> None:
        with self._lock:
            self._buf.clear()

    def features(self) -> AudioFeatures:
        window = self.recent(1.6)
        if len(window) < SR // 2:
            return AudioFeatures()
        hop = 320  # 20 ms
        n = len(window) // hop
        env = np.sqrt(np.mean(window[: n * hop].reshape(n, hop) ** 2, axis=1) + 1e-12)
        rms = float(np.mean(env[-12:]))
        self.base_rms = 0.98 * self.base_rms + 0.02 * min(rms, max(self.base_rms * 3, 0.02))
        ratio = rms / max(self.base_rms, 1e-4)
        loud = float(np.clip((ratio - 1.6) / 3.0, 0, 1)) if rms > 0.01 else 0.0
        # Modulationsspektrum der Hüllkurve: Lachen pulsiert mit ~4–6 Hz.
        e = env - env.mean()
        spec = np.abs(np.fft.rfft(e * np.hanning(len(e))))
        freqs = np.fft.rfftfreq(len(e), d=hop / SR)
        band = spec[(freqs >= 3) & (freqs <= 7)].sum()
        total = spec[(freqs >= 0.5) & (freqs <= 20)].sum() + 1e-9
        rhythm = float(band / total)
        laugh = float(np.clip((rhythm - 0.3) / 0.35, 0, 1)) * min(1.0, loud * 1.5 + (0.3 if ratio > 1.3 else 0.0))
        return AudioFeatures(rms=rms, loud=loud, laugh=laugh)


class LoopbackRecorder:
    """Nimmt Discord-Ton (Loopback) und optional das Mikrofon auf (Windows, `soundcard`)."""

    def __init__(self, monitor: AudioMonitor, device_hint: str = "", mic: bool = False,
                 on_error: Callable[[str], None] | None = None):
        self.monitor = monitor
        self.device_hint = device_hint
        self.mic = mic
        self.on_error = on_error or (lambda m: log.warning(m))
        self._stop = threading.Event()
        self._thread: threading.Thread | None = None

    def start(self) -> None:
        if self._thread and self._thread.is_alive():
            return
        self._stop.clear()
        self._thread = threading.Thread(target=self._run, name="qc-audio", daemon=True)
        self._thread.start()

    def stop(self) -> None:
        self._stop.set()

    def _run(self) -> None:
        try:
            import soundcard as sc  # type: ignore
        except Exception as exc:  # pragma: no cover - Windows-spezifisch
            self.on_error(f"Audio deaktiviert (soundcard nicht verfügbar: {exc})")
            return
        try:
            speaker = sc.default_speaker()
            if self.device_hint:
                for spk in sc.all_speakers():
                    if self.device_hint.lower() in spk.name.lower():
                        speaker = spk
                        break
            loop = sc.get_microphone(id=str(speaker.name), include_loopback=True)
            mic = sc.default_microphone() if self.mic else None
        except Exception as exc:  # pragma: no cover
            self.on_error(f"Audio-Gerät nicht gefunden: {exc}")
            return
        try:  # pragma: no cover - benötigt echte Hardware
            with loop.recorder(samplerate=SR, channels=1, blocksize=CHUNK) as rec_loop:
                rec_mic = mic.recorder(samplerate=SR, channels=1, blocksize=CHUNK) if mic else None
                if rec_mic:
                    rec_mic.__enter__()
                try:
                    while not self._stop.is_set():
                        data = rec_loop.record(numframes=CHUNK).reshape(-1)
                        if rec_mic:
                            data = 0.7 * data + 0.5 * rec_mic.record(numframes=CHUNK).reshape(-1)
                        self.monitor.feed(data)
                finally:
                    if rec_mic:
                        rec_mic.__exit__(None, None, None)
        except Exception as exc:  # pragma: no cover
            self.on_error(f"Audioaufnahme abgebrochen: {exc}")


class SampleAudio:
    """Synthetischer Ton für den Testmodus, synchron zum Beispielvideo."""

    def __init__(self, monitor: AudioMonitor, level_fn: Callable[[float], tuple[float, float]]):
        self.monitor = monitor
        self.level_fn = level_fn  # t -> (laugh_level, talk_level)
        self._stop = threading.Event()
        self._thread: threading.Thread | None = None
        self._rng = np.random.default_rng(7)

    def start(self) -> None:
        if self._thread and self._thread.is_alive():
            return
        self._stop.clear()
        self._thread = threading.Thread(target=self._run, name="qc-sample-audio", daemon=True)
        self._thread.start()

    def stop(self) -> None:
        self._stop.set()

    def _run(self) -> None:
        next_t = time.time()
        while not self._stop.is_set():
            now = time.time()
            t = np.arange(CHUNK) / SR + now
            laugh, talk = self.level_fn(now)
            noise = self._rng.standard_normal(CHUNK).astype(np.float32)
            # Lachen: Rauschen, mit 5 Hz amplitudenmoduliert. Reden: leise, langsam moduliert.
            sig = noise * (0.004 + talk * 0.03 * (0.6 + 0.4 * np.sin(2 * np.pi * 1.7 * t)))
            sig += noise * laugh * 0.25 * np.clip(np.sin(2 * np.pi * 5.0 * t), 0, 1)
            self.monitor.feed(sig.astype(np.float32))
            next_t += CHUNK / SR
            time.sleep(max(0.0, next_t - time.time()))


def transcribe(samples: np.ndarray, model_name: str = "small", language: str = "de") -> str:
    """Optional: lokale Transkription mit faster-whisper (nicht in requirements.txt)."""
    if samples is None or len(samples) < SR:
        return ""
    try:
        from faster_whisper import WhisperModel  # type: ignore
    except Exception:
        return ""
    global _WHISPER
    try:
        if _WHISPER is None or _WHISPER[0] != model_name:
            _WHISPER = (model_name, WhisperModel(model_name, device="cpu", compute_type="int8"))
        segments, _ = _WHISPER[1].transcribe(samples.astype(np.float32), language=language, vad_filter=True)
        return " ".join(s.text.strip() for s in segments)[:400]
    except Exception as exc:  # pragma: no cover
        log.warning("Transkription fehlgeschlagen: %s", exc)
        return ""


_WHISPER = None
