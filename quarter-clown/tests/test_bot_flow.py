"""Bot-Struktur und Zustimmungs-Flow (ohne echte Discord-Verbindung)."""
from __future__ import annotations

import asyncio

from quarter_clown.config import load_settings
from quarter_clown.session import Controller, Phase


def test_slash_commands_and_buttons(tmp_path):
    from quarter_clown.bot import ConsentView, QuarterClownBot

    async def run():
        s = load_settings()
        s.data_dir = tmp_path
        ctl = Controller(s)
        bot = QuarterClownBot(ctl, s, ctl.store)
        group = bot.tree.get_command("quarter")
        names = sorted(c.name for c in group.commands)
        view = ConsentView(bot)
        ids = sorted(i.custom_id for i in view.children)
        await ctl.shutdown()
        return names, ids

    names, ids = asyncio.run(run())
    assert names == ["setup", "status", "stop", "testmodus"]
    assert ids == ["qc:consent", "qc:decline", "qc:stop"]


class FakeBot:
    def __init__(self, members):
        self.members = members
        self.said = []

    def is_ready(self):
        return True

    def voice_members(self):
        return dict(self.members)

    def consent_embed(self, *a, **kw):
        return None

    async def say(self, channel_id, text="", embed=None, view=None):
        self.said.append(text)
        return None


def test_consent_pause_and_stop(tmp_path):
    async def run():
        s = load_settings()
        s.data_dir = tmp_path
        s.audio_enabled = False
        ctl = Controller(s)
        ctl.loop = asyncio.get_running_loop()
        bot = FakeBot({1: "A", 2: "B"})
        ctl.bot = bot
        g = ctl.store.guild
        g.voice_channel_id, g.text_channel_id, g.voice_channel_name = 10, 20, "Voice"
        ctl.capture_kind = "obs"   # kein Fenster nötig für den Ablauf-Test
        ctl._make_source = lambda: None

        await ctl.request_start()
        assert ctl.phase == Phase.AWAITING and not ctl.recording.is_set()
        ctl.on_consent(1, "A")
        await asyncio.sleep(0.05)
        assert ctl.phase == Phase.AWAITING            # B fehlt noch -> keine Aufnahme
        ctl.on_consent(2, "B")
        await asyncio.sleep(0.05)
        assert ctl.phase == Phase.RUNNING and ctl.recording.is_set()
        assert ctl.scheduler is not None and ctl.scheduler.t0 > 0

        bot.members[3] = "C"                           # neue Person -> Pause
        await ctl.on_roster_change(joined=(3, "C"))
        assert ctl.phase == Phase.PAUSED and not ctl.recording.is_set()
        ctl.on_consent(3, "C")
        await asyncio.sleep(0.05)
        assert ctl.phase == Phase.RUNNING

        sched = ctl.scheduler
        await ctl.stop("Test")
        assert ctl.phase == Phase.IDLE and sched.stopped and not ctl.store.session.active
        assert ctl.on_consent(1, "A") == "Gerade läuft keine Anfrage."
        await ctl.shutdown()

    asyncio.run(run())


def test_testmodus_command_registered(tmp_path):
    from quarter_clown.bot import QuarterClownBot

    async def run():
        s = load_settings()
        s.data_dir = tmp_path
        ctl = Controller(s)
        bot = QuarterClownBot(ctl, s, ctl.store)
        cmd = next(c for c in bot.tree.get_command("quarter").commands if c.name == "testmodus")
        params = {p.name: p for p in cmd.parameters}
        await ctl.shutdown()
        return params

    params = asyncio.run(run())
    assert set(params) == {"takt", "quelle", "anzahl", "kanal"}
    assert [c.value for c in params["takt"].choices] == [10, 30, 60]


def test_quick_test_run_posts_n_drops_then_stops(tmp_path, monkeypatch):
    """Testlauf: genau N Drops im Schnelltakt, danach automatisch Stopp; Normalbetrieb bleibt 15 min."""
    import time

    from quarter_clown import session as session_mod

    monkeypatch.setattr(session_mod, "TEST_INTERVALS", (2,))  # 2-s-Takt, damit der Test schnell ist

    async def run():
        s = load_settings()
        s.data_dir = tmp_path
        s.output_dir = tmp_path / "out"
        s.caption_mode = "local"
        ctl = Controller(s)
        ctl.set_caption_mode("local")
        ctl.loop = asyncio.get_running_loop()
        assert ctl.timing.interval == 900
        assert "Erlaubte Takte" in await ctl.request_test(interval=7)
        msg = await ctl.request_test(interval=2, sample=True, max_drops=3)
        assert "Testlauf gestartet" in msg and ctl.timing.interval == 2
        start = time.time()
        while ctl.session_active and time.time() - start < 15:
            await asyncio.sleep(0.1)
        await asyncio.sleep(2.5)                     # weiterer Takt: darf nichts mehr posten
        files = sorted(p.name for p in (tmp_path / "out" / "test").glob("*.png"))
        interval_after = ctl.timing.interval
        await ctl.shutdown()
        return files, interval_after, ctl

    files, interval_after, ctl = asyncio.run(run())
    assert len(files) == 3 and [f[-7:-4] for f in files] == ["001", "002", "003"]
    assert not ctl.session_active and interval_after == 900
