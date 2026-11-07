"""Meme-Erzeugung: Fallback bei API-Ausfall, Sicherheitsregeln, Layouts, Analyse."""
from __future__ import annotations

import asyncio
import io
import time

import numpy as np
from PIL import Image

from quarter_clown.analysis import Candidate, CandidatePool, FrameAnalyzer, Moment, detect_tiles
from quarter_clown.captions import COMPARE_LABELS, CaptionService, LocalCaptioner, TEMPLATES, validate
from quarter_clown.capture.sample import SampleCallSource, SampleClock, active_events
from quarter_clown.meme import Caption, render_meme, render_silence
from quarter_clown.producer import MemeProducer


def _candidate(score=3.0, signals=None, focus=(0.0, 0.0, 0.5, 0.5)):
    frame = Image.fromarray(SampleCallSource().grab())
    return Candidate(Moment(time.time(), score, signals or {"laugh": 0.9}, focus, 1), frame, trigger=frame)


class BrokenClaude:
    def caption(self, *a, **kw):
        raise ConnectionError("API down")


class SlowClaude:
    def caption(self, *a, timeout=1.0, **kw):
        time.sleep(3)
        return Caption("zu spät", source="claude")


class RudeClaude:
    def caption(self, *a, **kw):
        return Caption("Schau dir diese Frisur an", source="claude")


class QuoteClaude:
    def caption(self, *a, **kw):
        return Caption('Er sagte: „Ich bin der Beste“', source="claude")


class GoodClaude:
    def caption(self, *a, **kw):
        return Caption("Wenn alle gleichzeitig kapieren", "nur nicht was", source="claude")


def _png_ok(data: bytes) -> Image.Image:
    img = Image.open(io.BytesIO(data))
    assert img.format == "PNG" and img.width == 1080
    return img


def test_api_error_falls_back_to_local_template():
    svc = CaptionService(mode="claude", claude=BrokenClaude(), seed=1)
    res = svc.make([Image.new("RGB", (10, 10))], "laugh", {"laugh": 1}, "de", timeout=5)
    assert not res.used_ai and res.caption.source == "local"
    assert (res.caption.top, res.caption.bottom, "classic") in TEMPLATES["de"]["laugh"]
    assert "ConnectionError" in svc.last_error


def test_slow_api_still_delivers_meme_before_deadline():
    pool = CandidatePool()
    pool.add_candidate(_candidate())
    producer = MemeProducer(CaptionService(mode="claude", claude=SlowClaude()))

    async def run():
        started = time.monotonic()
        try:
            meme = await producer.produce(pool, budget=1.5, ai_timeout=1.0)
        except asyncio.TimeoutError:
            meme = await producer.produce_local(pool, budget=1.0)
        return meme, time.monotonic() - started

    meme, took = asyncio.run(run())
    assert took < 3.0 and not meme.used_ai
    _png_ok(meme.png)


def test_unsafe_ai_captions_are_rejected():
    for bad in (RudeClaude(), QuoteClaude()):
        res = CaptionService(mode="claude", claude=bad).make([Image.new("RGB", (8, 8))], "laugh", {}, "de", timeout=5)
        assert not res.used_ai and res.caption.source == "local"
    good = CaptionService(mode="claude", claude=GoodClaude()).make([Image.new("RGB", (8, 8))], "multi", {}, "de", timeout=5)
    assert good.used_ai and good.caption.source == "claude"


def test_validate_rules():
    assert validate(Caption("Du hast eine hässliche Nase")) == "Aussehen"
    assert validate(Caption('„Ich hab Hunger“')) == "erfundenes Zitat"
    assert validate(Caption('„Ich hab Hunger“'), transcript="ich hab hunger, sorry") is None
    assert validate(Caption("Gruppenreaktion freigeschaltet")) is None


def test_local_mode_without_api_key_and_swiss_style():
    svc = CaptionService(mode="auto", api_key="")
    assert not svc.ai_enabled
    res = svc.make([Image.new("RGB", (8, 8))], "dry", {}, "gsw", allow_compare=False)
    assert res.caption.top in {t for t, _, _ in TEMPLATES["gsw"]["dry"]}
    for style in TEMPLATES:   # alle Vorlagen bestehen die eigenen Regeln
        for cat, items in TEMPLATES[style].items():
            for top, bottom, fmt in items:
                assert validate(Caption(top, bottom, fmt=fmt)) is None, (style, cat, top)
    for style, labels in COMPARE_LABELS.items():
        for left, right in labels:
            assert validate(Caption("", fmt="compare", left=left, right=right)) is None


def test_brainrot_is_default_and_uses_all_formats():
    from quarter_clown.config import Settings

    assert Settings().caption_style == "brainrot"
    local = LocalCaptioner(seed=1)
    fmts = {local.caption(cat, "brainrot").fmt for cat in ("laugh", "multi", "grimace", "dry") for _ in range(30)}
    assert {"classic", "pov", "compare", "deepfry"} <= fmts
    # Ohne Einzelgesicht im Fokus nie ein Vergleichs-Meme
    assert all(local.caption("laugh", "brainrot", allow_compare=False).fmt != "compare" for _ in range(50))
    # Cringe-Vorlagen sind raus
    everything = " ".join(t + " " + b for st in TEMPLATES.values() for items in st.values() for t, b, _ in items).lower()
    assert "kommando" not in everything and "absolut niemand" not in everything


def test_empty_block_posts_prepared_silence_graphic():
    producer = MemeProducer(LocalCaptionerService())
    meme = asyncio.run(producer.produce(CandidatePool(), budget=2, ai_timeout=1))
    assert meme.kind == "silence"
    assert "stille" in meme.caption.top.lower()
    _png_ok(meme.png)


def test_low_activity_gives_dry_meme():
    pool = CandidatePool()
    pool.add_candidate(_candidate(score=0.2, signals={"gesture": 0.1}))
    meme = asyncio.run(MemeProducer(LocalCaptionerService()).produce(pool, budget=3, ai_timeout=1))
    assert meme.kind == "dry"


def test_all_layouts_render():
    for layout, sig in [("zoom", {"laugh": 1}), ("crop", {"multi": 1}), ("two_panel", {"scene": 1})]:
        img, used = render_meme(_candidate(signals=sig), Caption("Oben sehr lange Caption für das Handy", "unten"), layout)
        assert used == layout and img.width == 1080
    for fmt in ("pov", "compare", "deepfry"):
        cap = Caption("POV: test", fmt=fmt, doodle="scream", left="er im call:", right="er innerlich:")
        img, used = render_meme(_candidate(), cap, "zoom")
        assert img.width == 1080 and fmt in used or used.endswith("+pov")


def test_user_stickers_are_used(tmp_path, monkeypatch):
    import random

    from quarter_clown import doodles

    folder = tmp_path / "scream"
    folder.mkdir()
    Image.new("RGB", (50, 50), (255, 0, 255)).save(folder / "meins.png")
    monkeypatch.setattr(doodles, "STICKER_DIR", tmp_path)
    hits = sum(doodles.reaction_image("scream", (100, 100), random.Random(i)).getpixel((50, 50)) == (255, 0, 255)
               for i in range(20))
    assert hits >= 8
    assert render_silence().size == (1080, 1080)


def test_pool_keeps_only_few_and_rotate_deletes():
    pool = CandidatePool(keep=3)
    frame = np.zeros((90, 160, 3), dtype=np.uint8)
    for i in range(10):
        pool.offer(Moment(i * 10.0, 1.0 + i, {"laugh": 1}, (0, 0, 1, 1)), frame)
    items, fb = pool.snapshot()
    assert len(items) == 3 and items[0].score == 10.0
    assert pool.rotate() == 4
    assert pool.snapshot() == ([], None)


def test_analyzer_finds_moments_in_sample_material():
    clock = SampleClock()
    src = SampleCallSource(clock)
    analyzer = FrameAnalyzer()
    # Beispielmaterial "vorspulen": Zeitpunkt eines Gruppenlachers suchen
    laugh_t = next(x / 4 for x in range(400) if any(e.kind == "laugh" and len(e.who) == 4 for e in active_events(x / 4)))
    scores = []
    for k in range(-8, 6):
        clock.t0 = time.time() - (laugh_t + k * 0.25)
        scores.append(analyzer.analyze(src.grab(), t=k * 0.25).score)
    assert max(scores[8:]) > max(scores[1:6]) + 0.5   # Lachen schlägt Ruhephase


def test_tile_detection_on_discord_grid():
    img = np.asarray(Image.fromarray(SampleCallSource().grab()).convert("L").resize((192, 108)), dtype=np.float32) / 255
    assert len(detect_tiles(img)) == 4
    # Und genau so, wie der Analyzer im Live-Betrieb arbeitet:
    analyzer = FrameAnalyzer()
    src = SampleCallSource()
    for i in range(3):
        m = analyzer.analyze(src.grab(), t=float(i))
    assert len(analyzer.tiles) == 4 and m.focus in analyzer.tiles


class LocalCaptionerService(CaptionService):
    def __init__(self):
        super().__init__(mode="local")
        self.local = LocalCaptioner(seed=3)
