"""Ziele für fertige Memes: Discord-Textkanal oder lokaler Ordner (Testmodus)."""
from __future__ import annotations

import time
from pathlib import Path
from typing import TYPE_CHECKING

from .producer import Meme

if TYPE_CHECKING:  # pragma: no cover
    from .bot import QuarterClownBot


def meme_filename(session_id: str, block: int) -> str:
    # Der Dateiname ist gleichzeitig die Doppelpost-Markierung im Kanalverlauf.
    return f"quarter-clown_{session_id}_{block:03d}.png"


def drop_text(block: int, interval: float, meme: Meme, test: bool) -> str:
    mins = int(block * interval // 60)
    secs = int(block * interval % 60)
    stamp = f"{mins}:{secs:02d}"
    prefix = "🧪 Test-Drop" if test else "🤡⏰ Quarter Drop"
    extra = {"dry": " · ruhiger Block", "silence": " · keine verwertbare Aufnahme"}.get(meme.kind, "")
    return f"**{prefix} #{block}** · {stamp}{extra}"


class LocalPoster:
    """Speichert Memes nur lokal (Testmodus ohne Testkanal)."""

    def __init__(self, folder: Path, interval: float):
        self.folder = Path(folder)
        self.interval = interval
        self.folder.mkdir(parents=True, exist_ok=True)
        self.posted: list[tuple[int, float, str]] = []

    async def post(self, block: int, meme: Meme, session_id: str) -> bool:
        path = self.folder / meme_filename(session_id, block)
        path.write_bytes(meme.png)
        self.posted.append((block, time.time(), str(path)))
        return True

    async def exists(self, block: int, session_id: str) -> bool:
        return (self.folder / meme_filename(session_id, block)).exists()


class TestChannelRequired(RuntimeError):
    pass


class DiscordPoster:
    def __init__(self, bot: "QuarterClownBot", channel_id: int, interval: float, test_mode: bool,
                 test_channel_id: int | None = None, save_dir: Path | None = None):
        if test_mode:
            # Testposts gehen ausschliesslich in den explizit gewählten Testkanal.
            if not test_channel_id or channel_id != test_channel_id:
                raise TestChannelRequired("Testmodus postet nur in den explizit gewählten Testkanal.")
        self.bot = bot
        self.channel_id = channel_id
        self.interval = interval
        self.test_mode = test_mode
        self.save_dir = save_dir

    async def post(self, block: int, meme: Meme, session_id: str) -> bool:
        name = meme_filename(session_id, block)
        ok = await self.bot.send_meme(self.channel_id, meme.png, name, drop_text(block, self.interval, meme, self.test_mode))
        if ok and self.save_dir:
            self.save_dir.mkdir(parents=True, exist_ok=True)
            (self.save_dir / name).write_bytes(meme.png)
        return ok

    async def exists(self, block: int, session_id: str) -> bool:
        return await self.bot.has_recent_file(self.channel_id, meme_filename(session_id, block))
