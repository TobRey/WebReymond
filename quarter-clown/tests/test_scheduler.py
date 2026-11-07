"""Takt-Tests: genau ein Meme pro Intervall, kein Post nach Stopp, keine Doppelposts.

Die Tests nutzen einen verkürzten Takt (Sekundenbruchteile), damit sie schnell
laufen. Die Logik ist identisch mit dem 15-Minuten-Normalbetrieb.
"""
from __future__ import annotations

import asyncio
import time

import pytest

from quarter_clown.analysis import CandidatePool
from quarter_clown.config import NORMAL_INTERVAL_S, TEST_INTERVAL_S, Timing, load_settings
from quarter_clown.meme import Caption
from quarter_clown.producer import Meme
from quarter_clown.scheduler import BlockScheduler
from quarter_clown.state import StateStore

FAST = Timing(interval=0.8, lead=0.3, margin=0.05, ai_timeout=0.1, post_grace=0.25)


class FakeProducer:
    def __init__(self, delay: float = 0.0):
        self.delay = delay
        self.calls = 0

    def _meme(self, note=""):
        return Meme(b"png", Caption("x"), "meme", "crop", 1.0, note=note)

    async def produce(self, pool, budget, ai_timeout, exclude_below=-1.0):
        self.calls += 1
        if exclude_below >= 0:
            raise ValueError("kein besserer")
        await asyncio.wait_for(asyncio.sleep(self.delay), timeout=budget)
        return self._meme()

    async def produce_local(self, pool, budget):
        return self._meme("lokal")

    def silence_meme(self, note=""):
        return Meme(b"png", Caption("Stille"), "silence", "silence", 0.0, note=note)


class RecordingPoster:
    def __init__(self, fail_times: int = 0):
        self.posts: list[tuple[int, float]] = []
        self.fail_times = fail_times
        self.exists_calls = 0

    async def post(self, block, meme, session_id):
        if self.fail_times > 0:
            self.fail_times -= 1
            raise ConnectionError("Discord weg")
        self.posts.append((block, time.time()))
        return True

    async def exists(self, block, session_id):
        self.exists_calls += 1
        return any(b == block for b, _ in self.posts)


def make(tmp_path, t0=None, producer=None, poster=None, timing=FAST, store=None):
    store = store or StateStore(tmp_path / "state.json")
    t0 = time.time() if t0 is None else t0
    if not store.session.active:
        store.start_session(t0, timing.interval, True, 1)
    sched = BlockScheduler(session_id=store.session.id, t0=store.session.t0, timing=timing, pool=CandidatePool(),
                           producer=producer or FakeProducer(), poster=poster or RecordingPoster(), store=store)
    return sched, store


def test_fixed_intervals():
    assert NORMAL_INTERVAL_S == 900
    assert TEST_INTERVAL_S == 30
    assert load_settings(test_mode=False).timing.interval == 900
    assert load_settings(test_mode=True).timing.interval == 30


def test_exactly_one_meme_per_interval(tmp_path):
    async def run():
        sched, store = make(tmp_path)
        task = sched.start()
        await asyncio.sleep(FAST.interval * 3 + 0.3)
        sched.stop()
        await asyncio.gather(task, return_exceptions=True)
        return sched, store

    sched, store = asyncio.run(run())
    posts = sched.poster.posts
    assert [b for b, _ in posts] == [1, 2, 3]
    for block, ts in posts:
        deadline = sched.deadline(block)
        assert deadline - 0.01 <= ts <= deadline + 0.15, f"Block {block} nicht pünktlich"
    assert store.session.last_posted_block == 3


def test_no_post_after_stop(tmp_path):
    async def run():
        sched, store = make(tmp_path)
        task = sched.start()
        await asyncio.sleep(FAST.interval * 1.2)        # Block 1 gepostet
        store.stop_session("test")
        sched.stop()
        await asyncio.gather(task, return_exceptions=True)
        await asyncio.sleep(FAST.interval * 2.5)        # weitere Stichtage verstreichen
        return sched

    sched = asyncio.run(run())
    assert [b for b, _ in sched.poster.posts] == [1]


def test_stop_while_meme_is_prepared(tmp_path):
    """Stopp nach der Vorbereitung, aber vor dem Stichtag -> nichts wird gepostet."""
    async def run():
        sched, store = make(tmp_path)
        task = sched.start()
        await asyncio.sleep(FAST.interval - FAST.lead / 2)   # Meme ist vorbereitet
        assert sched.prepared is not None
        sched.stop()
        await asyncio.gather(task, return_exceptions=True)
        await asyncio.sleep(FAST.interval)
        return sched

    sched = asyncio.run(run())
    assert sched.poster.posts == []


def test_restart_does_not_repost_or_catch_up(tmp_path):
    """Neustart mitten in Block 5: Blöcke 1–2 gepostet, 3–4 verpasst -> weiter mit 5."""
    store = StateStore(tmp_path / "state.json")
    t0 = time.time() - FAST.interval * 4.4
    store.start_session(t0, FAST.interval, True, 1)
    store.mark_posted(store.session.id, 2)

    async def run():
        sched, _ = make(tmp_path, store=StateStore(tmp_path / "state.json"))  # frisch geladen = Neustart
        assert sched.next_block() == 5
        task = sched.start()
        await asyncio.sleep(FAST.interval * 1.0)
        sched.stop()
        await asyncio.gather(task, return_exceptions=True)
        return sched

    sched = asyncio.run(run())
    assert [b for b, _ in sched.poster.posts] == [5]


def test_store_blocks_duplicate_block(tmp_path):
    store = StateStore(tmp_path / "state.json")
    s = store.start_session(time.time(), 900, False, 1)
    assert store.mark_posting(s.id, 1)
    store.mark_posted(s.id, 1)
    assert not store.mark_posting(s.id, 1)           # gleicher Block nie zweimal
    reloaded = StateStore(tmp_path / "state.json")    # auch nicht nach Neustart
    assert not reloaded.mark_posting(s.id, 1)
    assert reloaded.mark_posting(s.id, 2)
    reloaded.stop_session("x")
    assert not reloaded.mark_posting(s.id, 3)        # gestoppt -> nie wieder


def test_reconnect_retry_does_not_duplicate(tmp_path):
    """Erster Sendeversuch scheitert (Verbindung weg) -> genau ein Post nach Wiederverbindung."""
    async def run():
        poster = RecordingPoster(fail_times=1)
        sched, _ = make(tmp_path, poster=poster)
        task = sched.start()
        await asyncio.sleep(FAST.interval + FAST.post_grace)
        sched.stop()
        await asyncio.gather(task, return_exceptions=True)
        return poster

    poster = asyncio.run(run())
    assert [b for b, _ in poster.posts] == [1]
    assert poster.exists_calls >= 1   # vor dem Wiederholen wurde der Kanalverlauf geprüft


def test_slow_producer_falls_back_in_time(tmp_path):
    """Hängt die Vorbereitung (z. B. KI), wird trotzdem pünktlich ein lokales Meme gepostet."""
    async def run():
        sched, _ = make(tmp_path, producer=FakeProducer(delay=10))
        task = sched.start()
        await asyncio.sleep(FAST.interval + 0.15)
        sched.stop()
        await asyncio.gather(task, return_exceptions=True)
        return sched

    sched = asyncio.run(run())
    assert [b for b, _ in sched.poster.posts] == [1]
    block, ts = sched.poster.posts[0]
    assert ts <= sched.deadline(1) + 0.15


@pytest.mark.parametrize("fail_times", [100])
def test_post_failure_skips_without_catch_up(tmp_path, fail_times):
    async def run():
        poster = RecordingPoster(fail_times=fail_times)
        sched, store = make(tmp_path, poster=poster)
        task = sched.start()
        await asyncio.sleep(FAST.interval + FAST.post_grace + 0.5)
        poster.fail_times = 0                       # Discord wieder da
        await asyncio.sleep(FAST.interval * 1.0)
        sched.stop()
        await asyncio.gather(task, return_exceptions=True)
        return poster, store

    poster, store = asyncio.run(run())
    blocks = [b for b, _ in poster.posts]
    assert 1 not in blocks                           # verpasster Block wird nicht nachgeschickt
    assert blocks == sorted(set(blocks))             # keine Duplikate
    assert 1 in store.session.skipped_blocks
