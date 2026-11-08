"""Gehosteter Modus: Server (Bot-Relay) <-> Windows-App über echtes HTTP/WebSocket.

Discord selbst ist durch `FakeDiscord` ersetzt; alles andere (Kopplung, RPC,
Kanal-Freigaben, Limits, Zustimmung, Testlauf, Stopp) läuft echt.
"""
from __future__ import annotations

import asyncio
import socket
import time

from aiohttp import web

from quarter_clown.config import load_settings
from quarter_clown.server.hub import Hub, ServerStore
from quarter_clown.session import Controller, Phase
from quarter_clown.state import GuildConfig

GID, VOICE, TEXT, TEST, OTHER = 111, 222, 333, 444, 999


class FakeDiscord:
    invite = "https://discord.com/oauth2/authorize?client_id=1"

    def __init__(self):
        self.members = {1: "Ana", 2: "Ben"}
        self.messages: list[tuple[int, str]] = []
        self.memes: list[tuple[int, str]] = []
        self.consents: list[int] = []

    def voice_members(self, guild_id, voice_id):
        return dict(self.members) if (guild_id, voice_id) == (GID, VOICE) else {}

    async def say(self, channel_id, text):
        self.messages.append((channel_id, text))
        return len(self.messages)

    async def post_consent(self, channel_id, title, participants, consented, note="", mention=""):
        self.consents.append(channel_id)
        return 42

    async def update_consent(self, *a):
        return None

    async def send_meme(self, channel_id, png, filename, content):
        self.memes.append((channel_id, filename))
        return True

    async def has_recent_file(self, channel_id, filename):
        return any(f == filename for _, f in self.memes)


def _port() -> int:
    with socket.socket() as s:
        s.bind(("127.0.0.1", 0))
        return s.getsockname()[1]


async def _start(tmp_path):
    hub = Hub(ServerStore(tmp_path / "server" / "server.json"), download_url="https://example.com/dl")
    fake = FakeDiscord()
    hub.ops = fake
    runner = web.AppRunner(hub.web_app())
    await runner.setup()
    port = _port()
    await web.TCPSite(runner, "127.0.0.1", port).start()
    return hub, fake, runner, f"http://127.0.0.1:{port}"


def _settings(tmp_path, url):
    s = load_settings()
    s.discord_token = ""
    s.data_dir = tmp_path / "app"
    s.output_dir = tmp_path / "app-out"
    s.server_url = url
    s.link_token = ""
    s.caption_mode = "local"
    s.audio_enabled = False
    return s


async def _wait(cond, timeout=5.0):
    end = time.time() + timeout
    while time.time() < end:
        if cond():
            return True
        await asyncio.sleep(0.05)
    return False


def test_pairing_relay_and_guards(tmp_path, monkeypatch):
    from quarter_clown import session as session_mod
    from quarter_clown.server import hub as hub_mod

    monkeypatch.setattr(session_mod, "TEST_INTERVALS", (2,))
    monkeypatch.setattr(hub_mod, "MIN_POST_GAP", 1.0)

    async def run():
        hub, fake, runner, url = await _start(tmp_path)
        try:
            # /quarter setup im Discord -> Code
            text = await hub.save_setup(GuildConfig(GID, "Freunde", VOICE, "Voice", TEXT, "memes", TEST, "test"))
            code = hub.store.guilds[GID].pair_code
            assert code in text and "example.com/dl" in text

            ctl = Controller(_settings(tmp_path, url))
            ctl.set_caption_mode("local")
            ctl.attach_bot()
            assert ctl.needs_pairing and "gekoppelt" in await ctl.request_start()

            # falscher Code wird abgelehnt
            try:
                await ctl.pair("ZZZZ-ZZZZ")
                raise AssertionError("falscher Code akzeptiert")
            except Exception as exc:
                assert "ungültig" in str(exc)

            # richtiger Code (auch klein und ohne Bindestrich) -> verbunden, Config übernommen
            assert await ctl.pair(code.replace("-", "").lower()) == "Freunde"
            assert await _wait(ctl.bot.is_ready)
            assert await _wait(lambda: ctl.store.guild.text_channel_id == TEXT)
            assert ctl.bot.voice_members() == {1: "Ana", 2: "Ben"}
            assert hub.store.guilds[GID].pair_code == ""          # Code nur einmal nutzbar
            assert "Freunde" in ctl.status()["bot_label"]

            # Status-Command vom Server -> App
            st = await hub.status(GID)
            assert st["phase"] == "idle"

            # Kanal-Schutz: App darf nicht in fremde Kanäle posten
            try:
                await ctl.bot.say(OTHER, "spam")
                raise AssertionError("fremder Kanal erlaubt")
            except Exception as exc:
                assert "nicht freigegeben" in str(exc)

            # /quarter testmodus vom Server: 3 Drops im 2-s-Takt, nur im Testkanal
            msg = await hub.request_test(GID, interval=2, sample=True, max_drops=3, channel_id=TEST, channel_name="test")
            assert "Testlauf gestartet" in msg
            assert await _wait(lambda: not ctl.session_active, timeout=20)
            assert [c for c, _ in fake.memes] == [TEST, TEST, TEST]
            assert [f[-7:-4] for _, f in fake.memes] == ["001", "002", "003"]

            # Live-Start mit Zustimmung über die Discord-Buttons (Server -> App)
            ctl.capture_kind = "obs"
            ctl._make_source = lambda: None
            assert "Anfrage" in await ctl.request_start()
            assert fake.consents == [TEXT] and ctl.phase == Phase.AWAITING
            assert "Danke" in await hub.consent(GID, 1, "Ana")
            assert ctl.phase == Phase.AWAITING
            await hub.consent(GID, 2, "Ben")
            assert await _wait(lambda: ctl.phase == Phase.RUNNING)
            assert await hub.session_active(GID)

            # Neue Person im Voice -> Pause
            fake.members[3] = "Cem"
            await hub.roster(GID, (3, "Cem"), None)
            assert await _wait(lambda: ctl.phase == Phase.PAUSED)

            # /quarter stop vom Server
            assert "gestoppt" in await hub.stop(GID, "Test-Stopp")
            assert ctl.phase == Phase.IDLE

            # App getrennt -> Commands melden das sauber
            await ctl.bot.close()
            assert await _wait(lambda: GID not in hub.links)
            assert await hub.status(GID) is None
            assert "nicht verbunden" in await hub.request_test(GID, interval=2, channel_id=TEST)
            await ctl.shutdown()
        finally:
            await runner.cleanup()

    asyncio.run(run())


def test_invalid_token_and_landing_page(tmp_path):
    import aiohttp

    async def run():
        hub, fake, runner, url = await _start(tmp_path)
        try:
            async with aiohttp.ClientSession() as http:
                async with http.get(url + "/") as r:
                    html = await r.text()
                    assert r.status == 200 and fake.invite in html and "example.com/dl" in html
                async with http.get(url + "/api/info") as r:
                    assert (await r.json())["invite_url"] == fake.invite
                async with http.get(url + "/ws", headers={"Authorization": "Bearer falsch"}) as r:
                    assert r.status == 401
                for _ in range(10):
                    await http.post(url + "/api/pair", json={"code": "AAAA-AAAA"})
                async with http.post(url + "/api/pair", json={"code": "AAAA-AAAA"}) as r:
                    assert r.status == 429                      # Brute-Force-Schutz
        finally:
            await runner.cleanup()

    asyncio.run(run())
