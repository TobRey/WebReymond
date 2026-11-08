"""Verbindung der Windows-App zum zentralen Quarter-Clown-Server (gehosteter Bot).

Kopplung einmalig per Code aus `/quarter setup` bzw. `/quarter verbinden`. Danach
verbindet sich die App bei jedem Start automatisch und verbindet sich nach
Unterbrechungen selbst neu. Der Bot-Token liegt nur auf dem Server.
"""
from __future__ import annotations

import asyncio
import base64
import logging
from typing import TYPE_CHECKING

import aiohttp

from . import __version__
from .link import MAX_MSG, PROTOCOL_VERSION, Peer
from .state import GuildConfig

if TYPE_CHECKING:  # pragma: no cover
    from .config import Settings
    from .session import Controller

log = logging.getLogger(__name__)


class PairingError(RuntimeError):
    pass


async def fetch_info(server_url: str) -> dict:
    async with aiohttp.ClientSession(timeout=aiohttp.ClientTimeout(total=8)) as http:
        async with http.get(f"{server_url}/api/info") as resp:
            resp.raise_for_status()
            return await resp.json()


async def pair(server_url: str, code: str) -> dict:
    """Tauscht den Code aus Discord gegen einen dauerhaften App-Schlüssel."""
    code = code.strip().upper().replace(" ", "")
    if not server_url:
        raise PairingError("Keine Server-Adresse eingebaut (QC_SERVER_URL fehlt).")
    try:
        async with aiohttp.ClientSession(timeout=aiohttp.ClientTimeout(total=10)) as http:
            async with http.post(f"{server_url}/api/pair", json={"code": code}) as resp:
                data = await resp.json(content_type=None)
                if resp.status != 200:
                    raise PairingError(data.get("error") or f"Fehler {resp.status}")
                return data
    except aiohttp.ClientError as exc:
        raise PairingError(f"Server nicht erreichbar: {exc}") from exc


def _ints(d: dict) -> dict[int, str]:
    return {int(k): str(v) for k, v in (d or {}).items()}


class RemoteBotLink:
    """Gleiche Schnittstelle wie `bot.LocalBotLink`, aber über den Server."""

    def __init__(self, controller: "Controller", settings: "Settings"):
        self.ctl = controller
        self.settings = settings
        self.peer: Peer | None = None
        self.members: dict[int, str] = {}
        self.invite = ""
        self.state = "nicht gekoppelt" if not settings.link_token else "verbinde …"
        self._stop = asyncio.Event()
        self._task: asyncio.Task | None = None
        self._first = True

    # ----------------------------------------------------------- status
    def is_ready(self) -> bool:
        return self.peer is not None and not self.peer.closed.is_set()

    @property
    def label(self) -> str:
        if self.is_ready():
            return f"Verbunden mit {self.settings.linked_guild or 'Discord'}"
        return f"Bot: {self.state}"

    # -------------------------------------------------------- lifecycle
    def start(self) -> None:
        if self._task is None or self._task.done():
            self._stop.clear()
            self._task = asyncio.get_running_loop().create_task(self.run())

    async def run(self) -> None:
        delay = 2.0
        while not self._stop.is_set() and self.settings.link_token:
            try:
                await self._connect_once()
                delay = 2.0
            except PairingError as exc:
                self.state = str(exc)
                self.ctl.log(f"Verbindung zum Server abgelehnt: {exc}")
                return
            except Exception as exc:
                self.state = "Server nicht erreichbar – neuer Versuch …"
                log.info("Verbindung fehlgeschlagen: %s", exc)
            if self._stop.is_set():
                break
            try:
                await asyncio.wait_for(self._stop.wait(), timeout=delay)
            except asyncio.TimeoutError:
                pass
            delay = min(30.0, delay * 2)

    async def _connect_once(self) -> None:
        url = self.settings.server_url.replace("https://", "wss://").replace("http://", "ws://") + "/ws"
        headers = {"Authorization": f"Bearer {self.settings.link_token}", "X-QC-Version": __version__}
        async with aiohttp.ClientSession() as http:
            try:
                ws = await http.ws_connect(url, headers=headers, heartbeat=25, max_msg_size=MAX_MSG)
            except aiohttp.WSServerHandshakeError as exc:
                if exc.status in (401, 403):
                    raise PairingError("Kopplung ungültig – bitte in Discord /quarter verbinden und neu koppeln") from exc
                raise
            async with ws:
                self.peer = Peer(ws, self._handlers(), name="server")
                reader = asyncio.get_running_loop().create_task(self.peer.run())
                try:
                    hello = await self.peer.call("hello", version=__version__, protocol=PROTOCOL_VERSION)
                    self._apply_hello(hello)
                    self.state = "verbunden"
                    self.ctl.log(f"Mit Quarter-Clown-Server verbunden ({self.settings.linked_guild})")
                    if self._first:   # nach App-Neustart: laufende Session pausiert fortsetzen
                        self._first = False
                        await self.ctl.on_bot_ready()
                    await reader
                finally:
                    reader.cancel()
                    self.peer = None
                    self.state = "Verbindung unterbrochen – verbinde neu …"
                    self.ctl.log("Verbindung zum Server unterbrochen")

    def _apply_hello(self, hello: dict) -> None:
        self.invite = hello.get("invite_url", "")
        self.members = _ints(hello.get("members", {}))
        cfg = hello.get("config")
        if cfg:
            self.ctl.apply_guild_config(GuildConfig(**cfg))
            if cfg.get("guild_name") and cfg["guild_name"] != self.settings.linked_guild:
                self.settings.linked_guild = cfg["guild_name"]
                self.settings.save_user()

    async def close(self) -> None:
        self._stop.set()
        if self.peer is not None:
            await self.peer.ws.close()

    # ------------------------------------------------- server -> app
    def _handlers(self) -> dict:
        ctl = self.ctl

        async def config(cfg: dict, members: dict | None = None):
            ctl.apply_guild_config(GuildConfig(**cfg))
            if members is not None:
                self.members = _ints(members)
            return True

        async def roster(members: dict, joined=None, left=None):
            self.members = _ints(members)
            await ctl.on_roster_change(joined=tuple(joined) if joined else None, left=tuple(left) if left else None)
            return True

        async def consent(user_id: int, name: str):
            return ctl.on_consent(int(user_id), name)

        async def decline(user_id: int, name: str):
            return await ctl.on_decline(int(user_id), name)

        async def stop(reason: str):
            if not ctl.session_active:
                return "Es läuft gerade keine Aufnahme."
            await ctl.stop(reason)
            return "⛔ Aufnahme gestoppt. Es werden keine weiteren Memes gepostet."

        async def request_test(**kw):
            return await ctl.request_test(**kw)

        async def status():
            return ctl.status()

        return {"config": config, "roster": roster, "consent": consent, "decline": decline,
                "stop": stop, "request_test": request_test, "status": status}

    # ------------------------------------------------- app -> server
    async def _call(self, method: str, timeout: float = 20.0, **params):
        if not self.is_ready():
            raise ConnectionError("nicht mit dem Quarter-Clown-Server verbunden")
        return await self.peer.call(method, timeout=timeout, **params)  # type: ignore[union-attr]

    def voice_members(self) -> dict[int, str]:
        return dict(self.members)

    async def say(self, channel_id, text):
        return await self._call("say", channel_id=channel_id, text=text)

    async def post_consent(self, channel_id, title, participants, consented, note="", mention=""):
        return await self._call("post_consent", channel_id=channel_id, title=title,
                                participants={str(k): v for k, v in participants.items()},
                                consented=sorted(consented), note=note, mention=mention)

    async def update_consent(self, channel_id, message_id, title, participants, consented, note=""):
        return await self._call("update_consent", channel_id=channel_id, message_id=message_id, title=title,
                                participants={str(k): v for k, v in participants.items()},
                                consented=sorted(consented), note=note)

    async def send_meme(self, channel_id, png: bytes, filename: str, content: str) -> bool:
        return bool(await self._call("send_meme", timeout=40.0, channel_id=channel_id, filename=filename,
                                     content=content, png=base64.b64encode(png).decode("ascii")))

    async def has_recent_file(self, channel_id, filename) -> bool:
        return bool(await self._call("has_recent_file", channel_id=channel_id, filename=filename))
