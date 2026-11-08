"""Kleines RPC-Protokoll über WebSocket (JSON) zwischen Windows-App und Quarter-Clown-Server.

Beide Seiten können Methoden der Gegenseite aufrufen:
    {"t": "call", "id": 7, "m": "send_meme", "p": {...}}
    {"t": "result", "id": 7, "ok": true, "r": ...}   bzw.   {"t": "result", "id": 7, "ok": false, "e": "..."}
Funktioniert mit aiohttp-WebSockets auf Client- und Serverseite.
"""
from __future__ import annotations

import asyncio
import itertools
import json
import logging
from typing import Any, Awaitable, Callable

import aiohttp

log = logging.getLogger(__name__)
MAX_MSG = 16 * 1024 * 1024
PROTOCOL_VERSION = 1

Handler = Callable[..., Awaitable[Any]]


class RpcError(RuntimeError):
    pass


class Peer:
    def __init__(self, ws, handlers: dict[str, Handler], name: str = "peer"):
        self.ws = ws
        self.handlers = handlers
        self.name = name
        self._ids = itertools.count(1)
        self._pending: dict[int, asyncio.Future] = {}
        self.closed = asyncio.Event()

    async def call(self, method: str, timeout: float = 15.0, **params: Any) -> Any:
        if self.closed.is_set():
            raise RpcError("Verbindung getrennt")
        cid = next(self._ids)
        fut = asyncio.get_running_loop().create_future()
        self._pending[cid] = fut
        try:
            await self.ws.send_str(json.dumps({"t": "call", "id": cid, "m": method, "p": params}))
            return await asyncio.wait_for(fut, timeout)
        finally:
            self._pending.pop(cid, None)

    async def run(self) -> None:
        """Empfangsschleife bis zur Trennung."""
        try:
            async for msg in self.ws:
                if msg.type != aiohttp.WSMsgType.TEXT:
                    if msg.type in (aiohttp.WSMsgType.ERROR, aiohttp.WSMsgType.CLOSE):
                        break
                    continue
                try:
                    data = json.loads(msg.data)
                except json.JSONDecodeError:
                    continue
                if data.get("t") == "call":
                    asyncio.get_running_loop().create_task(self._handle(data))
                elif data.get("t") == "result":
                    fut = self._pending.get(data.get("id"))
                    if fut and not fut.done():
                        if data.get("ok"):
                            fut.set_result(data.get("r"))
                        else:
                            fut.set_exception(RpcError(str(data.get("e", "Fehler"))))
        finally:
            self.closed.set()
            for fut in self._pending.values():
                if not fut.done():
                    fut.set_exception(RpcError("Verbindung getrennt"))

    async def _handle(self, data: dict) -> None:
        cid, method = data.get("id"), data.get("m")
        handler = self.handlers.get(method)
        try:
            if handler is None:
                raise RpcError(f"unbekannte Methode {method}")
            result = await handler(**(data.get("p") or {}))
            reply = {"t": "result", "id": cid, "ok": True, "r": result}
        except Exception as exc:
            log.warning("%s: %s fehlgeschlagen: %s", self.name, method, exc)
            reply = {"t": "result", "id": cid, "ok": False, "e": str(exc)[:300]}
        try:
            await self.ws.send_str(json.dumps(reply, default=str))
        except Exception:
            pass
