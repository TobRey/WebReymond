"""Schriften: Impact (Meme-Klassiker) unter Windows, sonst fette Ersatzschriften."""
from __future__ import annotations

import os
from functools import lru_cache
from pathlib import Path

from PIL import ImageFont

_WIN = Path(os.environ.get("WINDIR", r"C:\Windows")) / "Fonts"
MEME_FONTS = [
    _WIN / "impact.ttf", _WIN / "ariblk.ttf", _WIN / "arialbd.ttf", _WIN / "seguibl.ttf",
    Path("/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf"),
    Path("/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf"),
    Path("/System/Library/Fonts/Supplemental/Impact.ttf"),
]
POV_FONTS = [
    _WIN / "arialbd.ttf", _WIN / "seguisb.ttf",
    Path("/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf"),
    Path("/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf"),
]
UI_FONTS = [
    _WIN / "seguisb.ttf", _WIN / "segoeuib.ttf", _WIN / "arialbd.ttf",
    Path("/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf"),
]


def _first(paths: list[Path]) -> str | None:
    for p in paths:
        if p.exists():
            return str(p)
    return None


@lru_cache(maxsize=64)
def load_font(size: int, meme: bool = False, kind: str = "") -> ImageFont.FreeTypeFont | ImageFont.ImageFont:
    lists = {"pov": POV_FONTS, "meme": MEME_FONTS}
    path = _first(lists.get(kind) or (MEME_FONTS if meme else UI_FONTS)) or _first(MEME_FONTS)
    if path:
        return ImageFont.truetype(path, size)
    return ImageFont.load_default(size)
