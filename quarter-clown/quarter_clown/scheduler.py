"""Exakter Block-Takt: pro Intervall genau ein Meme, pünktlich zum Stichtag.

Block k hat den Stichtag  t0 + k * interval  (k = 1, 2, 3 …). Ablauf pro Block:

1. Bis `deadline - lead` sammeln (läuft in der Aufnahme im Hintergrund).
2. Meme vorbereiten – spätestens bei `deadline - margin` muss es fertig sein.
   Dauert das zu lange (z. B. KI hängt), wird lokal mit Vorlage gerendert.
3. Bis zum Stichtag: taucht ein deutlich besserer Moment auf, wird neu
   gerendert – aber nur, wenn es rechtzeitig fertig wird.
4. Am Stichtag: Pool leeren (verworfene Kandidaten sind weg) und posten.

Doppelpost-Schutz: Vor dem Senden wird der Block im StateStore reserviert,
danach als gepostet markiert. Ein Block, dessen Stichtag (plus Kulanz) schon
vorbei ist, wird übersprungen und nie nachgeschickt.
"""
from __future__ import annotations

import asyncio
import logging
import math
import time
from typing import Callable, Protocol

from .analysis import CandidatePool
from .config import Timing
from .producer import Meme, MemeProducer
from .state import StateStore

log = logging.getLogger(__name__)


class Poster(Protocol):
    async def post(self, block: int, meme: Meme, session_id: str) -> bool: ...
    async def exists(self, block: int, session_id: str) -> bool: ...


EventFn = Callable[[str, dict], None]


class BlockScheduler:
    def __init__(self, *, session_id: str, t0: float, timing: Timing, pool: CandidatePool,
                 producer: MemeProducer, poster: Poster, store: StateStore,
                 clock: Callable[[], float] = time.time, on_event: EventFn | None = None,
                 refine_threshold: float = 1.25):
        self.session_id = session_id
        self.t0 = t0
        self.timing = timing
        self.pool = pool
        self.producer = producer
        self.poster = poster
        self.store = store
        self.clock = clock
        self.on_event = on_event or (lambda kind, data: None)
        self.refine_threshold = refine_threshold
        self._stopped = asyncio.Event()
        self._task: asyncio.Task | None = None
        self.current_block = 0
        self.prepared: Meme | None = None

    # --------------------------------------------------------------- api
    def start(self) -> asyncio.Task:
        self._task = asyncio.get_running_loop().create_task(self.run(), name=f"qc-scheduler-{self.session_id}")
        return self._task

    def stop(self) -> None:
        self._stopped.set()
        if self._task and not self._task.done():
            self._task.cancel()

    @property
    def stopped(self) -> bool:
        return self._stopped.is_set()

    def deadline(self, block: int) -> float:
        return self.t0 + block * self.timing.interval

    def next_block(self) -> int:
        """Erster Block, dessen Stichtag noch erreichbar ist und der nicht schon gepostet wurde."""
        elapsed = self.clock() - self.t0
        upcoming = max(1, math.floor(elapsed / self.timing.interval) + 1)
        # Kulanz: Block, dessen Stichtag gerade erst vorbei ist, darf noch raus.
        prev = upcoming - 1
        if prev >= 1 and self.clock() <= self.deadline(prev) + self.timing.post_grace:
            upcoming = prev
        return max(upcoming, self.store.session.last_posted_block + 1)

    def seconds_to_next(self) -> float:
        block = self.current_block or self.next_block()
        return max(0.0, self.deadline(block) - self.clock())

    # --------------------------------------------------------------- loop
    async def _sleep_until(self, when: float) -> bool:
        """Schläft bis `when`. False, wenn währenddessen gestoppt wurde."""
        while not self.stopped:
            delay = when - self.clock()
            if delay <= 0:
                return True
            try:
                await asyncio.wait_for(self._stopped.wait(), timeout=min(delay, 0.5))
            except asyncio.TimeoutError:
                pass
        return False

    async def run(self) -> None:
        try:
            block = self.next_block()
            while not self.stopped:
                self.current_block = block
                deadline = self.deadline(block)
                if self.clock() > deadline + self.timing.post_grace:
                    self.store.mark_skipped(self.session_id, block)
                    self.on_event("skipped", {"block": block, "reason": "Stichtag verpasst"})
                    block = self.next_block()
                    continue
                if not await self._sleep_until(deadline - self.timing.lead):
                    break
                meme = await self._prepare(block, deadline)
                meme = await self._refine(block, deadline, meme)
                if not await self._sleep_until(deadline):
                    break
                dropped = self.pool.rotate()
                self.on_event("rotated", {"block": block, "dropped": dropped})
                await self._post(block, deadline, meme)
                self.prepared = None
                block = max(block + 1, self.next_block())
        except asyncio.CancelledError:
            pass
        finally:
            self.on_event("scheduler_ended", {"block": self.current_block})

    async def _prepare(self, block: int, deadline: float) -> Meme:
        budget = deadline - self.timing.margin - self.clock()
        try:
            meme = await self.producer.produce(self.pool, budget, self.timing.ai_timeout)
        except (asyncio.TimeoutError, Exception) as exc:  # KI hängt oder Fehler -> lokal
            log.warning("Block %s: Vorbereitung fehlgeschlagen (%s) – lokale Vorlage", block, exc)
            try:
                meme = await self.producer.produce_local(self.pool, max(0.5, deadline - self.clock() - 0.2))
            except Exception:
                meme = self.producer.silence_meme("Notfallgrafik")
            meme.note = f"Fallback: {type(exc).__name__}"
        self.prepared = meme
        self.on_event("prepared", {"block": block, "meme": meme})
        return meme

    async def _refine(self, block: int, deadline: float, meme: Meme) -> Meme:
        """Späteren, besseren Moment nur nutzen, wenn er rechtzeitig fertig wird."""
        seen_version = self.pool.version
        while not self.stopped:
            remaining = deadline - self.timing.margin - self.clock()
            if remaining < 1.0:
                break
            if self.pool.version != seen_version:
                seen_version = self.pool.version
                best = self.pool.best_score()
                if best > max(meme.score * self.refine_threshold, meme.score + 0.3):
                    try:
                        better = await self.producer.produce(self.pool, remaining, self.timing.ai_timeout,
                                                             exclude_below=meme.score)
                        if better.score > meme.score and self.clock() <= deadline - self.timing.margin + 0.5:
                            meme = better
                            self.prepared = meme
                            self.on_event("refined", {"block": block, "meme": meme})
                    except (asyncio.TimeoutError, Exception) as exc:
                        self.on_event("refine_skipped", {"block": block, "reason": type(exc).__name__})
                    continue
            await asyncio.sleep(min(0.5, max(0.05, remaining - 1.0)))
        return meme

    async def _post(self, block: int, deadline: float, meme: Meme) -> None:
        if self.stopped or not self.store.mark_posting(self.session_id, block):
            return
        attempt = 0
        while not self.stopped and self.clock() <= deadline + self.timing.post_grace:
            attempt += 1
            try:
                if attempt > 1 and await self.poster.exists(block, self.session_id):
                    self.store.mark_posted(self.session_id, block)
                    self.on_event("posted", {"block": block, "meme": meme, "recovered": True})
                    return
                if self.stopped or not self.store.may_post(self.session_id, block):
                    return
                if await self.poster.post(block, meme, self.session_id):
                    self.store.mark_posted(self.session_id, block)
                    self.on_event("posted", {"block": block, "meme": meme})
                    return
            except asyncio.CancelledError:
                raise
            except Exception as exc:
                self.on_event("post_failed", {"block": block, "error": str(exc)[:120]})
            await asyncio.sleep(min(2.0, 0.5 * attempt, max(0.05, self.timing.post_grace / 5)))
        if not self.stopped:
            self.store.mark_skipped(self.session_id, block)
            self.on_event("skipped", {"block": block, "reason": "Posten fehlgeschlagen"})

