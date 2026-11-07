"""Erzeugt aus den Kandidaten eines Blocks genau ein fertiges Meme."""
from __future__ import annotations

import asyncio
import logging
import random
import time
from dataclasses import dataclass, field
from typing import Callable

from .analysis import Candidate, CandidatePool
from .audio import transcribe
from .captions import SILENCE_TEXT, CaptionService, category_for
from .meme import Caption, choose_layout, render_meme, render_silence, to_png

log = logging.getLogger(__name__)
RENDER_RESERVE = 1.2  # Sekunden, die fürs Rendern nach der Caption reserviert bleiben


@dataclass
class Meme:
    png: bytes
    caption: Caption
    kind: str            # meme | dry | silence
    layout: str
    score: float
    candidate_id: int = 0
    used_ai: bool = False
    note: str = ""
    created: float = field(default_factory=time.time)


class MemeProducer:
    def __init__(self, captions: CaptionService, style: Callable[[], str] = lambda: "brainrot",
                 transcribe_enabled: bool = False, whisper_model: str = "small", min_score: float = 0.9):
        self.captions = captions
        self.style = style
        self.transcribe_enabled = transcribe_enabled
        self.whisper_model = whisper_model
        self.min_score = min_score
        self._silence_cache: dict[str, bytes] = {}
        self.rng = random.Random()

    # Vorbereitete Grafik – einmal gerendert, danach sofort verfügbar.
    def silence_png(self, style: str | None = None) -> bytes:
        style = style or self.style()
        if style not in self._silence_cache:
            self._silence_cache[style] = to_png(render_silence(SILENCE_TEXT.get(style, SILENCE_TEXT["brainrot"])))
        return self._silence_cache[style]

    def silence_meme(self, note: str = "") -> Meme:
        style = self.style()
        return Meme(self.silence_png(style), Caption(SILENCE_TEXT.get(style, SILENCE_TEXT["brainrot"])), "silence",
                    "silence", 0.0, note=note)

    async def produce(self, pool: CandidatePool, budget: float, ai_timeout: float,
                      exclude_below: float = -1.0) -> Meme:
        """Erzeugt ein Meme innerhalb von `budget` Sekunden (wirft TimeoutError sonst)."""
        candidates, fallback = pool.snapshot()
        best: Candidate | None = candidates[0] if candidates else fallback
        if best is None:
            return self.silence_meme("keine verwertbare Aufnahme")
        if best.score <= exclude_below:
            raise ValueError("kein besserer Kandidat")
        kind = "meme" if best.score >= self.min_score else "dry"
        category = category_for(best.moment.signals, best.score, self.min_score)
        style = self.style()
        layout = choose_layout(best)
        allow_compare = layout == "zoom"   # Vergleich nur, wenn ein Gesicht/eine Kachel im Fokus ist
        started = time.monotonic()

        def work() -> Meme:
            transcript = ""
            if self.transcribe_enabled and best.audio is not None and budget > 12:
                transcript = transcribe(best.audio, self.whisper_model, "de")
            remaining = budget - (time.monotonic() - started) - RENDER_RESERVE
            frames = [best.trigger, best.frame] if best.trigger is not None and best.moment.signals.get("scene", 0) > 0.4 else [best.frame]
            result = self.captions.make(frames, category, best.moment.signals, style, transcript,
                                        timeout=min(ai_timeout, remaining), allow_compare=allow_compare)
            img, used = render_meme(best, result.caption, layout, rng=self.rng)
            return Meme(to_png(img), result.caption, kind, used, best.score, best.id, result.used_ai, result.reason)

        return await asyncio.wait_for(asyncio.to_thread(work), timeout=max(0.1, budget))

    async def produce_local(self, pool: CandidatePool, budget: float) -> Meme:
        """Notfallpfad ohne KI – nur Vorlage + Rendering."""
        candidates, fallback = pool.snapshot()
        best = candidates[0] if candidates else fallback
        if best is None:
            return self.silence_meme("keine verwertbare Aufnahme")
        category = category_for(best.moment.signals, best.score, self.min_score)
        style = self.style()

        def work() -> Meme:
            layout = choose_layout(best)
            cap = self.captions.local.caption(category, style, allow_compare=layout == "zoom")
            img, used = render_meme(best, cap, layout, rng=self.rng)
            kind = "meme" if best.score >= self.min_score else "dry"
            return Meme(to_png(img), cap, kind, used, best.score, best.id, False, "Notfall-Vorlage")

        return await asyncio.wait_for(asyncio.to_thread(work), timeout=max(0.1, budget))
