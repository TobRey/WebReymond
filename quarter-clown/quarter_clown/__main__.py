"""Einstieg: `python -m quarter_clown [--test] [--headless --blocks N]`."""
from __future__ import annotations

import argparse
import asyncio
import logging
import sys
import time

from .config import load_settings


def main(argv: list[str] | None = None) -> int:
    ap = argparse.ArgumentParser(prog="quarter_clown", description="Quarter Clown – alle 15 Minuten ein Meme")
    ap.add_argument("--test", action="store_true", help="Testmodus: Beispielmaterial, 30-Sekunden-Takt")
    ap.add_argument("--headless", action="store_true", help="ohne Fenster (nur mit --test sinnvoll)")
    ap.add_argument("--blocks", type=int, default=3, help="Headless: nach so vielen Drops stoppen")
    ap.add_argument("--local", action="store_true", help="nur lokale Caption-Vorlagen, keine API")
    ap.add_argument("-v", "--verbose", action="store_true")
    args = ap.parse_args(argv)

    logging.basicConfig(level=logging.DEBUG if args.verbose else logging.INFO,
                        format="%(asctime)s %(levelname)s %(name)s: %(message)s")
    logging.getLogger("discord").setLevel(logging.WARNING)
    settings = load_settings(test_mode=args.test)
    if args.local:
        settings.caption_mode = "local"

    if args.headless:
        if not args.test:
            print("Headless ist nur im Testmodus erlaubt (Zustimmung läuft über die App/Discord).")
            return 2
        return asyncio.run(_headless(settings, args.blocks))

    try:
        from .app import App
    except ImportError as exc:
        print(f"GUI nicht verfügbar ({exc}). Installiere Python mit tkinter oder nutze --test --headless.")
        return 1
    App(settings).run()
    return 0


async def _headless(settings, blocks: int) -> int:
    from .session import Controller

    ctl = Controller(settings)
    if settings.caption_mode == "local":
        ctl.set_caption_mode("local")
    ctl.loop = asyncio.get_running_loop()
    bot_task = None
    if settings.discord_token:
        from .bot import QuarterClownBot

        ctl.bot = QuarterClownBot(ctl, settings, ctl.store)
        bot_task = asyncio.create_task(ctl.bot.start(settings.discord_token))
        for _ in range(60):
            if ctl.bot.is_ready():
                break
            await asyncio.sleep(0.5)
    print(await ctl.request_start())
    started = time.time()
    try:
        while ctl.blocks_posted < blocks and ctl.session_active:
            await asyncio.sleep(0.5)
            if time.time() - started > blocks * settings.timing.interval + 60:
                break
    except (KeyboardInterrupt, asyncio.CancelledError):
        pass
    await ctl.shutdown()
    if bot_task:
        await ctl.bot.close()
    return 0


if __name__ == "__main__":
    sys.exit(main())
