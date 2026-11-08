"""Quarter-Clown-Server: zentraler Discord-Bot + Relay zu den Windows-Apps der Kunden.

- Kunden laden den Bot per Link ein und koppeln ihre App mit einem Code.
- Pro Discord-Server ist genau eine App verbunden (WebSocket).
- Der Server postet nur in die per /quarter setup bzw. /quarter testmodus erlaubten
  Kanäle, mit Grössen- und Ratenlimits – eine App kann den Bot nicht für Spam missbrauchen.
"""
from __future__ import annotations

import asyncio
import base64
import hashlib
import json
import logging
import os
import re
import secrets
import time
from dataclasses import asdict, dataclass, field
from pathlib import Path
from typing import Any

from aiohttp import web

from ..config import ASSETS
from ..link import MAX_MSG, Peer
from ..state import GuildConfig

log = logging.getLogger(__name__)
CODE_ALPHABET = "ABCDEFGHJKLMNPQRSTUVWXYZ23456789"   # ohne 0/O/1/I
CODE_TTL = 30 * 60
FILENAME_RE = re.compile(r"^quarter-clown_[a-f0-9]{8}_\d{3}\.png$")
MAX_PNG = 8 * 1024 * 1024
MIN_POST_GAP = 8.0          # schnellster Testtakt ist 10 s
SAY_BUDGET = (30, 600.0)    # max. 30 Textnachrichten pro 10 Minuten und Server


def hash_token(token: str) -> str:
    return hashlib.sha256(token.encode()).hexdigest()


def new_code() -> str:
    raw = "".join(secrets.choice(CODE_ALPHABET) for _ in range(8))
    return f"{raw[:4]}-{raw[4:]}"


@dataclass
class GuildRecord:
    config: dict = field(default_factory=dict)
    token_hash: str = ""
    pair_code: str = ""
    pair_expires: float = 0.0
    linked_at: float = 0.0
    adhoc_channels: list[int] = field(default_factory=list)


class ServerStore:
    def __init__(self, path: Path):
        self.path = Path(path)
        self.guilds: dict[int, GuildRecord] = {}
        if self.path.exists():
            try:
                raw = json.loads(self.path.read_text(encoding="utf-8"))
                for gid, rec in raw.get("guilds", {}).items():
                    self.guilds[int(gid)] = GuildRecord(**rec)
            except (OSError, json.JSONDecodeError, TypeError):
                log.exception("Serverdaten nicht lesbar")

    def save(self) -> None:
        self.path.parent.mkdir(parents=True, exist_ok=True)
        tmp = self.path.with_suffix(".tmp")
        tmp.write_text(json.dumps({"guilds": {str(k): asdict(v) for k, v in self.guilds.items()}}, indent=1), "utf-8")
        os.replace(tmp, self.path)

    def get(self, gid: int) -> GuildRecord:
        return self.guilds.setdefault(int(gid), GuildRecord())


class _Bucket:
    def __init__(self, n: int, per: float):
        self.n, self.per, self.times = n, per, []  # type: ignore[var-annotated]

    def take(self) -> bool:
        now = time.monotonic()
        self.times = [t for t in self.times if now - t < self.per]
        if len(self.times) >= self.n:
            return False
        self.times.append(now)
        return True


class Hub:
    """HostedBackend für die Bot-Commands + Relay-Endpunkte für die Apps."""

    hosted = True

    def __init__(self, store: ServerStore, public_url: str = "", download_url: str = "",
                 admin_role: str = "Quarter Clown Admin"):
        self.store = store
        self.public_url = public_url.rstrip("/")
        self.download_url = download_url
        self.admin_role = admin_role
        self.ops: Any = None                    # QuarterClownBot (oder Attrappe in Tests)
        self.links: dict[int, Peer] = {}
        self._last_post: dict[int, float] = {}
        self._say: dict[int, _Bucket] = {}
        self._pair_tries: dict[str, _Bucket] = {}

    # ------------------------------------------------------- helpers
    def invite(self) -> str:
        return getattr(self.ops, "invite", "") if self.ops is not None else ""

    def guild_config(self, guild_id: int) -> GuildConfig | None:
        rec = self.store.guilds.get(int(guild_id)) if guild_id else None
        if rec is None or not rec.config:
            return None
        return GuildConfig(**rec.config)

    def _members(self, gid: int) -> dict[str, str]:
        cfg = self.guild_config(gid)
        if cfg is None or self.ops is None:
            return {}
        return {str(k): v for k, v in self.ops.voice_members(gid, cfg.voice_channel_id).items()}

    def _allowed_channels(self, gid: int) -> set[int]:
        rec = self.store.guilds.get(gid)
        cfg = self.guild_config(gid)
        if rec is None or cfg is None:
            return set()
        return {c for c in (cfg.text_channel_id, cfg.test_channel_id, *rec.adhoc_channels) if c}

    def _check_channel(self, gid: int, channel_id) -> int:
        cid = int(channel_id or 0)
        if cid not in self._allowed_channels(gid):
            raise PermissionError("Kanal nicht freigegeben – nur Meme-/Testkanal aus /quarter setup oder /quarter testmodus")
        return cid

    def _issue_code(self, gid: int) -> str:
        rec = self.store.get(gid)
        rec.pair_code = new_code()
        rec.pair_expires = time.time() + CODE_TTL
        self.store.save()
        return rec.pair_code

    def _offline_text(self) -> str:
        return ("💤 Die Quarter-Clown-App ist nicht verbunden. Starte sie auf deinem PC"
                + (f" (Download: {self.download_url})" if self.download_url else "") + ".")

    async def _app_call(self, gid: int, method: str, timeout: float = 8.0, **params):
        peer = self.links.get(int(gid))
        if peer is None or peer.closed.is_set():
            return None
        try:
            return await peer.call(method, timeout=timeout, **params)
        except Exception as exc:
            log.info("App von %s antwortet nicht auf %s: %s", gid, method, exc)
            return None

    # --------------------------------------------- Backend für Commands
    async def save_setup(self, cfg: GuildConfig) -> str:
        rec = self.store.get(cfg.guild_id)
        rec.config = asdict(cfg)
        rec.adhoc_channels = []
        self.store.save()
        if int(cfg.guild_id) in self.links:
            await self._app_call(cfg.guild_id, "config", cfg=rec.config, members=self._members(cfg.guild_id))
            return "✅ Deine Quarter-Clown-App ist verbunden und hat die neuen Einstellungen übernommen."
        return self._pair_text(self._issue_code(cfg.guild_id))

    def _pair_text(self, code: str) -> str:
        dl = f"[App herunterladen]({self.download_url})" if self.download_url else "die Quarter-Clown-App installieren"
        return (f"**1.** {dl} und starten.\n**2.** In der App diesen Code eingeben: **`{code}`**\n"
                f"Der Code ist 30 Minuten gültig. Neuer Code: `/quarter verbinden`")

    async def new_pair_code(self, guild_id: int) -> str:
        return self._pair_text(self._issue_code(guild_id))

    async def status(self, guild_id: int) -> dict | None:
        return await self._app_call(guild_id, "status", timeout=5)

    async def session_active(self, guild_id: int) -> bool:
        st = await self.status(guild_id)
        return bool(st and st.get("phase") != "idle")

    async def stop(self, guild_id: int, reason: str) -> str:
        res = await self._app_call(guild_id, "stop", reason=reason)
        return res if res is not None else "Die App ist nicht verbunden – es läuft keine Aufnahme."

    async def request_test(self, guild_id: int, **kw) -> str:
        if int(guild_id) not in self.links:
            return self._offline_text()
        cid = int(kw.get("channel_id") or 0)
        rec = self.store.get(guild_id)
        if cid and cid not in self._allowed_channels(int(guild_id)):
            rec.adhoc_channels = (rec.adhoc_channels + [cid])[-3:]   # per Command explizit gewählter Testkanal
            self.store.save()
        res = await self._app_call(guild_id, "request_test", timeout=20, **kw)
        return res if res is not None else self._offline_text()

    async def consent(self, guild_id: int, user_id: int, name: str) -> str:
        res = await self._app_call(guild_id, "consent", user_id=user_id, name=name)
        return res if res is not None else self._offline_text()

    async def decline(self, guild_id: int, user_id: int, name: str) -> str:
        res = await self._app_call(guild_id, "decline", user_id=user_id, name=name)
        return res if res is not None else "Okay – die App ist ohnehin nicht verbunden, es wird nichts aufgenommen."

    async def roster(self, guild_id: int, joined, left) -> None:
        if int(guild_id) in self.links:
            asyncio.get_running_loop().create_task(self._app_call(
                guild_id, "roster", members=self._members(guild_id),
                joined=list(joined) if joined else None, left=list(left) if left else None))

    async def on_ready(self, bot) -> None:
        log.info("Bot bereit, %s Server konfiguriert", len(self.store.guilds))

    # ----------------------------------------------- App -> Server (RPC)
    def _app_handlers(self, gid: int) -> dict:
        ops = self.ops

        async def hello(version: str = "", protocol: int = 1):
            cfg = self.guild_config(gid)
            return {"config": asdict(cfg) if cfg else None, "members": self._members(gid), "invite_url": self.invite()}

        async def voice_members():
            return self._members(gid)

        async def say(channel_id, text: str):
            cid = self._check_channel(gid, channel_id)
            if not self._say.setdefault(gid, _Bucket(*SAY_BUDGET)).take():
                raise PermissionError("zu viele Nachrichten")
            return await ops.say(cid, str(text)[:1500])

        async def post_consent(channel_id, title, participants, consented, note="", mention=""):
            cid = self._check_channel(gid, channel_id)
            if not self._say.setdefault(gid, _Bucket(*SAY_BUDGET)).take():
                raise PermissionError("zu viele Nachrichten")
            mention = " ".join(m for m in str(mention).split() if re.fullmatch(r"<@\d+>", m))   # nur User-Erwähnungen
            return await ops.post_consent(cid, str(title)[:200], {int(k): str(v)[:80] for k, v in participants.items()},
                                          {int(x) for x in consented}, str(note)[:300], mention)

        async def update_consent(channel_id, message_id, title, participants, consented, note=""):
            cid = self._check_channel(gid, channel_id)
            await ops.update_consent(cid, int(message_id), str(title)[:200],
                                     {int(k): str(v)[:80] for k, v in participants.items()},
                                     {int(x) for x in consented}, str(note)[:300])
            return True

        async def send_meme(channel_id, filename: str, content: str, png: str):
            cid = self._check_channel(gid, channel_id)
            if not FILENAME_RE.match(filename or ""):
                raise ValueError("ungültiger Dateiname")
            data = base64.b64decode(png, validate=True)
            if len(data) > MAX_PNG or not data.startswith(b"\x89PNG\r\n\x1a\n"):
                raise ValueError("kein gültiges PNG oder zu gross")
            now = time.monotonic()
            if now - self._last_post.get(gid, 0.0) < MIN_POST_GAP:
                raise PermissionError("zu schnell hintereinander")
            self._last_post[gid] = now
            return await ops.send_meme(cid, data, filename, str(content)[:300])

        async def has_recent_file(channel_id, filename: str):
            cid = self._check_channel(gid, channel_id)
            if not FILENAME_RE.match(filename or ""):
                return False
            return await ops.has_recent_file(cid, filename)

        return {"hello": hello, "voice_members": voice_members, "say": say, "post_consent": post_consent,
                "update_consent": update_consent, "send_meme": send_meme, "has_recent_file": has_recent_file}

    # --------------------------------------------------------- HTTP
    def web_app(self) -> web.Application:
        app = web.Application(client_max_size=1024 * 64)
        app.add_routes([
            web.get("/", self.h_index),
            web.get("/logo.svg", self.h_logo),
            web.get("/health", self.h_health),
            web.get("/api/info", self.h_info),
            web.post("/api/pair", self.h_pair),
            web.get("/ws", self.h_ws),
        ])
        return app

    async def h_health(self, request: web.Request) -> web.Response:
        return web.json_response({"ok": True, "bot": bool(self.invite()), "apps": len(self.links)})

    async def h_info(self, request: web.Request) -> web.Response:
        return web.json_response({"invite_url": self.invite(), "download_url": self.download_url})

    async def h_logo(self, request: web.Request) -> web.Response:
        return web.Response(body=(ASSETS / "logo.svg").read_bytes(), content_type="image/svg+xml",
                            headers={"Cache-Control": "public, max-age=86400"})

    async def h_index(self, request: web.Request) -> web.Response:
        html = (Path(__file__).parent / "landing.html").read_text(encoding="utf-8")
        html = html.replace("{{INVITE_URL}}", self.invite() or "#").replace("{{DOWNLOAD_URL}}", self.download_url or "#")
        return web.Response(text=html, content_type="text/html", headers={
            "Content-Security-Policy": "default-src 'self'; style-src 'unsafe-inline'; img-src 'self'",
            "X-Content-Type-Options": "nosniff"})

    async def h_pair(self, request: web.Request) -> web.Response:
        ip = request.headers.get("X-Forwarded-For", request.remote or "?").split(",")[0].strip()
        if not self._pair_tries.setdefault(ip, _Bucket(10, 600.0)).take():
            return web.json_response({"error": "Zu viele Versuche – bitte in 10 Minuten nochmal."}, status=429)
        try:
            code = str((await request.json()).get("code", "")).strip().upper().replace(" ", "")
        except Exception:
            return web.json_response({"error": "Ungültige Anfrage"}, status=400)
        if len(code) == 8:
            code = f"{code[:4]}-{code[4:]}"
        now = time.time()
        for gid, rec in self.store.guilds.items():
            if rec.pair_code and secrets.compare_digest(rec.pair_code, code) and rec.pair_expires > now:
                token = secrets.token_urlsafe(32)
                rec.token_hash = hash_token(token)
                rec.pair_code, rec.pair_expires, rec.linked_at = "", 0.0, now
                self.store.save()
                old = self.links.pop(gid, None)   # alte App-Kopplung ist damit ungültig
                if old is not None:
                    await old.ws.close()
                cfg = rec.config or {}
                log.info("App gekoppelt mit %s (%s)", cfg.get("guild_name"), gid)
                return web.json_response({"token": token, "guild_id": gid, "guild_name": cfg.get("guild_name", "")})
        return web.json_response({"error": "Code ungültig oder abgelaufen. In Discord /quarter verbinden für einen neuen Code."},
                                 status=404)

    async def h_ws(self, request: web.Request) -> web.StreamResponse:
        auth = request.headers.get("Authorization", "")
        token = auth[7:] if auth.startswith("Bearer ") else ""
        gid = next((g for g, rec in self.store.guilds.items()
                    if token and rec.token_hash and secrets.compare_digest(rec.token_hash, hash_token(token))), None)
        if gid is None:
            raise web.HTTPUnauthorized(text="Kopplung ungültig")
        ws = web.WebSocketResponse(heartbeat=30, max_msg_size=MAX_MSG)
        await ws.prepare(request)
        old = self.links.get(gid)
        if old is not None:
            await old.ws.close()
        peer = Peer(ws, self._app_handlers(gid), name=f"app-{gid}")
        self.links[gid] = peer
        log.info("App verbunden: %s", gid)
        try:
            await peer.run()
        finally:
            if self.links.get(gid) is peer:
                del self.links[gid]
            log.info("App getrennt: %s", gid)
        return ws
