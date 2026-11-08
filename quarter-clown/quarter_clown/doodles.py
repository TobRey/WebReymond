"""Krakelige MS-Paint-Reaktionsfratzen und Sticker (alles selbst gezeichnet).

Die Doodles werden neben das echte Webcam-Gesicht gesetzt („er im call:“ /
„er innerlich:“). Eigene Reaktionsbilder kannst du in `stickers/<art>/`
(z. B. `stickers/scream/`) oder direkt in `stickers/` ablegen – die App nimmt
sie dann zufällig statt der eingebauten Doodles.
"""
from __future__ import annotations

import math
import random
from pathlib import Path

from PIL import Image, ImageDraw

from .config import user_dir

DOODLES = ("scream", "stare", "cry", "smug", "shock")
STICKER_DIR = user_dir() / "stickers"
INK = (12, 12, 12)
TEAR = (60, 140, 255)


def _wobble(cx, cy, rx, ry, rng, jitter=0.06, n=36, a0=0.0, a1=2 * math.pi):
    pts = []
    for i in range(n + 1):
        a = a0 + (a1 - a0) * i / n
        k = 1 + rng.uniform(-jitter, jitter)
        pts.append((cx + rx * k * math.cos(a), cy + ry * k * math.sin(a)))
    return pts


def _shape(d, pts, fill=None, width=8, closed=True):
    if fill is not None:
        d.polygon(pts, fill=fill)
    line = pts + [pts[0]] if closed else pts
    d.line(line, fill=INK, width=width, joint="curve")


def _line(d, pts, rng, width=8, fill=INK):
    jittered = [(x + rng.uniform(-3, 3), y + rng.uniform(-3, 3)) for x, y in pts]
    d.line(jittered, fill=fill, width=width, joint="curve")


def draw_doodle(kind: str, size: tuple[int, int] = (540, 675), seed: int | None = None) -> Image.Image:
    """Zeichnet eine Fratze im 2010er-Paint-Stil (bewusst hässlich gezeichnet, nicht die Leute)."""
    rng = random.Random(seed)
    W, H = size
    s = 0.5  # in halber Auflösung zeichnen, dann pixelig hochskalieren
    w, h = int(W * s), int(H * s)
    img = Image.new("RGB", (w, h), "white")
    d = ImageDraw.Draw(img)
    cx, cy = w / 2, h * 0.46
    rx, ry = w * 0.30, h * 0.33
    lw = max(3, int(w / 90))
    # Shirt
    _shape(d, _wobble(cx, h * 1.08, w * 0.48, h * 0.3, rng, 0.03, a0=math.pi, a1=2 * math.pi), fill=INK, width=lw)
    # Ohren / Kopf
    for side in (-1, 1):
        _shape(d, _wobble(cx + side * rx * 0.98, cy, rx * 0.16, ry * 0.2, rng), fill="white", width=lw)
    head = _wobble(cx, cy, rx, ry, rng, 0.04)
    _shape(d, head, fill="white", width=lw)
    ey = cy - ry * 0.18
    ex = rx * 0.38

    if kind == "scream":
        for side in (-1, 1):
            _shape(d, _wobble(cx + side * ex, ey, rx * 0.22, ry * 0.2, rng), fill=INK, width=lw)
            d.ellipse((cx + side * ex - 5, ey - ry * 0.08, cx + side * ex + 3, ey - ry * 0.08 + 8), fill="white")
        _line(d, [(cx - 4, ey + 4), (cx - 10, cy + ry * 0.15)], rng, lw)
        mouth = _wobble(cx, cy + ry * 0.52, rx * 0.5, ry * 0.42, rng, 0.07)
        _shape(d, mouth, fill=(40, 20, 20), width=lw)
        _shape(d, _wobble(cx, cy + ry * 0.8, rx * 0.3, ry * 0.13, rng), fill=(230, 120, 140), width=max(2, lw // 2))
        _line(d, [(cx - rx * 0.42, cy + ry * 0.24), (cx + rx * 0.42, cy + ry * 0.24)], rng, max(3, lw), fill="white")
        for side in (-1, 1):  # Hände hoch
            hx = cx + side * rx * 1.45
            _line(d, [(hx, h * 0.98), (hx + side * 10, cy + ry * 0.2), (hx, cy - ry * 0.2)], rng, lw)
            for f in range(4):
                _line(d, [(hx, cy - ry * 0.2), (hx + side * (f - 1.5) * 9, cy - ry * 0.45)], rng, lw)
    elif kind == "stare":
        for side in (-1, 1):
            d.ellipse((cx + side * ex - 5, ey - 5, cx + side * ex + 5, ey + 5), fill=INK)
            _line(d, [(cx + side * ex - 18, ey - 22), (cx + side * ex + 18, ey - 22)], rng, lw)
        _line(d, [(cx - rx * 0.25, cy + ry * 0.45), (cx + rx * 0.25, cy + ry * 0.45)], rng, lw)
        d.polygon([(cx + rx * 0.75, cy - ry * 0.55), (cx + rx * 0.68, cy - ry * 0.35), (cx + rx * 0.82, cy - ry * 0.35)],
                  fill=TEAR)
    elif kind == "cry":
        for side in (-1, 1):
            d.arc((cx + side * ex - 22, ey - 12, cx + side * ex + 22, ey + 16), 200, 340, fill=INK, width=lw)
            x = cx + side * ex
            d.rectangle((x - 7, ey + 6, x + 7, h), fill=TEAR)
        mouth = _wobble(cx, cy + ry * 0.48, rx * 0.35, ry * 0.18, rng, 0.12)
        _shape(d, mouth, fill=(40, 20, 20), width=lw)
    elif kind == "smug":
        for side in (-1, 1):
            d.ellipse((cx + side * ex - 8, ey - 2, cx + side * ex + 8, ey + 12), fill=INK)
            _line(d, [(cx + side * ex - 20, ey), (cx + side * ex + 20, ey - 3)], rng, lw)
        _line(d, [(cx + ex - 22, ey - 34), (cx + ex + 22, ey - 44)], rng, lw)
        d.arc((cx - rx * 0.4, cy + ry * 0.05, cx + rx * 0.5, cy + ry * 0.55), 20, 120, fill=INK, width=lw)
    else:  # shock
        for side in (-1, 1):
            _shape(d, _wobble(cx + side * ex, ey, rx * 0.2, ry * 0.18, rng), fill="white", width=lw)
            d.ellipse((cx + side * ex - 4, ey - 4, cx + side * ex + 4, ey + 4), fill=INK)
        _shape(d, _wobble(cx, cy + ry * 0.5, rx * 0.12, ry * 0.14, rng), fill=(40, 20, 20), width=lw)
        for side in (-1, 1):
            _line(d, [(cx + side * rx * 0.95, cy + ry * 0.4), (cx + side * rx * 0.7, cy + ry * 0.1)], rng, lw * 2, fill=(255, 220, 190))
    img = img.resize((W, H), Image.NEAREST)
    return img


def user_sticker(kind: str, rng: random.Random) -> Image.Image | None:
    """Eigenes Reaktionsbild aus `stickers/<kind>/` oder `stickers/` (falls vorhanden)."""
    for folder in (STICKER_DIR / kind, STICKER_DIR):
        if folder.is_dir():
            files = [p for p in folder.iterdir() if p.suffix.lower() in {".png", ".jpg", ".jpeg", ".webp", ".gif"}]
            if files:
                try:
                    return Image.open(rng.choice(files)).convert("RGB")
                except OSError:
                    return None
    return None


def reaction_image(kind: str, size: tuple[int, int], rng: random.Random) -> Image.Image:
    custom = user_sticker(kind, rng)
    if custom is not None and rng.random() < 0.7:
        from PIL import ImageOps

        return ImageOps.fit(custom, size, Image.LANCZOS)
    return draw_doodle(kind if kind in DOODLES else "stare", size, seed=rng.randrange(1 << 30))


def draw_skull(size: int, rng: random.Random) -> Image.Image:
    """Kleiner Totenkopf-Sticker (statt Emoji, funktioniert ohne Emoji-Schrift)."""
    im = Image.new("RGBA", (size, size), (0, 0, 0, 0))
    d = ImageDraw.Draw(im)
    c = size / 2
    lw = max(2, size // 22)
    d.ellipse((size * 0.12, size * 0.08, size * 0.88, size * 0.72), fill="white", outline=INK, width=lw)
    d.rectangle((size * 0.3, size * 0.55, size * 0.7, size * 0.88), fill="white", outline=INK, width=lw)
    d.rectangle((size * 0.3 + lw, size * 0.5, size * 0.7 - lw, size * 0.62), fill="white")
    for x in (0.4, 0.5, 0.6):
        d.line((size * x, size * 0.72, size * x, size * 0.88), fill=INK, width=lw)
    for side in (-1, 1):
        d.ellipse((c + side * size * 0.17 - size * 0.12, size * 0.3, c + side * size * 0.17 + size * 0.12, size * 0.52), fill=INK)
    d.polygon([(c, size * 0.52), (c - size * 0.05, size * 0.62), (c + size * 0.05, size * 0.62)], fill=INK)
    return im.rotate(rng.uniform(-25, 25), resample=Image.BICUBIC, expand=True)


def sticker_root() -> Path:
    return STICKER_DIR
