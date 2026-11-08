"""Quarter-Clown-Server starten: `python -m quarter_clown.server`.

Umgebungsvariablen:
    DISCORD_BOT_TOKEN   Token des zentralen Bots (nur hier, nie in der App)
    QC_PUBLIC_URL       z. B. https://quarterclown.example.ch
    QC_DOWNLOAD_URL     Link zur Setup.exe (Standard: neuestes GitHub-Release)
    QC_SERVER_DATA      Datenordner (Standard: ./server-data)
    PORT                HTTP-Port (Standard: 8080)
"""
from __future__ import annotations

import asyncio
import logging
import os
import signal
from pathlib import Path

from aiohttp import web

from ..bot import QuarterClownBot
from .hub import Hub, ServerStore

try:
    from dotenv import load_dotenv
except ImportError:  # pragma: no cover
    load_dotenv = None


async def main() -> None:
    if load_dotenv is not None:
        load_dotenv()
    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(name)s: %(message)s")
    logging.getLogger("discord").setLevel(logging.WARNING)
    token = os.environ.get("DISCORD_BOT_TOKEN", "").strip()
    if not token:
        raise SystemExit("DISCORD_BOT_TOKEN fehlt")
    data = Path(os.getenv("QC_SERVER_DATA", "server-data"))
    hub = Hub(ServerStore(data / "server.json"), public_url=os.getenv("QC_PUBLIC_URL", ""),
              download_url=os.getenv("QC_DOWNLOAD_URL", "https://github.com/TobRey/quarter-clown/releases/latest/download/QuarterClown-Setup.exe"))
    bot = QuarterClownBot(hub)
    hub.ops = bot

    runner = web.AppRunner(hub.web_app())
    await runner.setup()
    site = web.TCPSite(runner, "0.0.0.0", int(os.getenv("PORT", "8080")))
    await site.start()
    logging.info("HTTP läuft auf Port %s", os.getenv("PORT", "8080"))

    stop = asyncio.Event()
    loop = asyncio.get_running_loop()
    for sig in (signal.SIGINT, signal.SIGTERM):
        try:
            loop.add_signal_handler(sig, stop.set)
        except NotImplementedError:  # pragma: no cover - Windows
            pass
    bot_task = asyncio.create_task(bot.start(token))
    await asyncio.wait([bot_task, asyncio.create_task(stop.wait())], return_when=asyncio.FIRST_COMPLETED)
    await bot.close()
    await runner.cleanup()


if __name__ == "__main__":
    asyncio.run(main())
