"""Meme-Rendering: Zuschnitt, Kachel-Zoom, 2-Panel, Vergleich, POV, Deep-Fry.

Stil: fette weisse Schrift mit schwarzer Kontur (Handy-lesbar), kleiner
„QUARTER CLOWN“-Stempel unten rechts. Formate:
- classic:  Impact-Caption oben/unten
- pov:      TikTok-artige schwarze Textboxen
- compare:  Webcam-Gesicht links, krakelige Reaktionsfratze rechts („er im call:“ / „er innerlich:“)
- deepfry:  übersättigt, JPEG-zerstört, roter Kreis, Totenkopf-Sticker
Alles läuft lokal mit Pillow.
"""
from __future__ import annotations

import io
import random
import textwrap
from dataclasses import dataclass
from functools import lru_cache

from PIL import Image, ImageChops, ImageDraw, ImageEnhance, ImageFilter, ImageOps, ImageStat

from .analysis import Candidate
from .config import ASSETS
from .doodles import draw_skull, reaction_image
from .fonts import load_font

LIME = (182, 255, 46)
PURPLE = (162, 59, 255)
DARK = (30, 31, 34)
GREY = (43, 45, 49)
OUT_W = 1080


@dataclass
class Caption:
    top: str
    bottom: str = ""
    source: str = "local"   # claude | local
    note: str = ""
    fmt: str = "classic"    # classic | pov | compare | deepfry
    doodle: str = "stare"   # scream | stare | cry | smug | shock (für compare)
    left: str = ""          # Label links (compare)
    right: str = ""         # Label rechts (compare)

FORMATS = ("classic", "pov", "compare", "deepfry")


# --------------------------------------------------------------- layout
def choose_layout(c: Candidate) -> str:
    s = c.moment.signals
    if c.trigger is not None and s.get("scene", 0) >= 0.45 and _differs(c.trigger, c.frame):
        return "two_panel"
    if c.trigger is not None and s.get("laugh", 0) >= 0.5 and s.get("multi", 0) < 0.5 and _differs(c.trigger, c.frame, 6):
        return "two_panel"
    if s.get("multi", 0) >= 0.5 or c.moment.active_cells >= 3:
        return "crop"
    x0, y0, x1, y1 = c.moment.focus
    if (x1 - x0) * (y1 - y0) < 0.6:
        return "zoom"
    return "crop"


def _differs(a: Image.Image, b: Image.Image, thresh: float = 10.0) -> bool:
    sa = a.convert("L").resize((64, 36))
    sb = b.convert("L").resize((64, 36))
    return ImageStat.Stat(ImageChops.difference(sa, sb)).mean[0] > thresh


def _crop_aspect(img: Image.Image, box: tuple[float, float, float, float], aspect: float) -> Image.Image:
    """Schneidet um `box` (normiert) mit Seitenverhältnis `aspect` (w/h) aus."""
    W, H = img.size
    x0, y0, x1, y1 = box[0] * W, box[1] * H, box[2] * W, box[3] * H
    cx, cy = (x0 + x1) / 2, (y0 + y1) / 2
    w, h = max(x1 - x0, 1), max(y1 - y0, 1)
    if w / h > aspect:
        h = w / aspect
    else:
        w = h * aspect
    w, h = min(w, W), min(h, H)
    if w / h > aspect:
        w = h * aspect
    else:
        h = w / aspect
    left = min(max(cx - w / 2, 0), W - w)
    top = min(max(cy - h / 2, 0), H - h)
    return img.crop((int(left), int(top), int(left + w), int(top + h)))


def _fit(img: Image.Image, w: int, h: int) -> Image.Image:
    return ImageOps.fit(img, (w, h), Image.LANCZOS)


# ----------------------------------------------------------------- text
def _wrap(draw: ImageDraw.ImageDraw, text: str, font, max_w: int) -> list[str]:
    words = text.split()
    lines: list[str] = []
    cur = ""
    for word in words:
        trial = f"{cur} {word}".strip()
        if draw.textlength(trial, font=font) <= max_w or not cur:
            cur = trial
        else:
            lines.append(cur)
            cur = word
    if cur:
        lines.append(cur)
    return lines


def draw_caption(img: Image.Image, text: str, where: str = "top", max_lines: int = 3, scale: float = 1.0) -> None:
    text = " ".join(text.upper().split())
    if not text:
        return
    draw = ImageDraw.Draw(img)
    W, H = img.size
    max_w = int(W * 0.92)
    ref = min(W * scale, H * 1.25)  # bei breiten Bildern nicht alles zuschreiben
    size = int(ref * 0.095)
    min_size = max(24, int(ref * 0.05))
    while True:
        font = load_font(size, meme=True)
        lines = _wrap(draw, text, font, max_w)
        if (len(lines) <= max_lines and all(draw.textlength(l, font=font) <= max_w for l in lines)) or size <= min_size:
            break
        size -= 4
    if len(lines) > max_lines:
        lines = lines[:max_lines]
        lines[-1] = textwrap.shorten(lines[-1] + " …", width=max(8, len(lines[-1])), placeholder=" …")
    stroke = max(3, size // 8)
    line_h = int(size * 1.08)
    block_h = line_h * len(lines)
    # unten Platz für den Stempel lassen
    y = int(H * 0.025) if where == "top" else H - block_h - int(W * 0.085)
    for line in lines:
        draw.text((W // 2, y), line, font=font, fill="white", stroke_width=stroke, stroke_fill="black", anchor="ma")
        y += line_h


@lru_cache(maxsize=1)
def _logo() -> Image.Image | None:
    p = ASSETS / "logo.png"
    try:
        return Image.open(p).convert("RGBA")
    except OSError:
        return None


def draw_stamp(img: Image.Image) -> None:
    """Kleiner, leicht schräger „QUARTER CLOWN“-Stempel unten rechts."""
    W, H = img.size
    size = max(16, int(W * 0.026))
    font = load_font(size, meme=True)
    label = "QUARTER CLOWN"
    probe = ImageDraw.Draw(img)
    tw = int(probe.textlength(label, font=font))
    icon = int(size * 1.7)
    pad = int(size * 0.45)
    sw, sh = tw + icon + pad * 3, icon + pad
    stamp = Image.new("RGBA", (sw, sh), (0, 0, 0, 0))
    d = ImageDraw.Draw(stamp)
    d.rounded_rectangle((0, 0, sw - 1, sh - 1), radius=sh // 3, fill=LIME + (235,), outline=DARK + (255,), width=max(2, size // 8))
    logo = _logo()
    if logo is not None:
        stamp.alpha_composite(logo.resize((icon, icon), Image.LANCZOS), (pad, (sh - icon) // 2))
    d.text((pad * 2 + icon, sh // 2), label, font=font, fill=PURPLE + (255,), anchor="lm",
           stroke_width=max(1, size // 14), stroke_fill=DARK + (255,))
    stamp = stamp.rotate(6, resample=Image.BICUBIC, expand=True)
    margin = int(W * 0.022)
    base = img.convert("RGBA")
    base.alpha_composite(stamp, (W - stamp.width - margin, H - stamp.height - margin))
    img.paste(base.convert("RGB"))


# --------------------------------------------------------------- render
def _base(c: Candidate, layout: str) -> tuple[Image.Image, str]:
    if layout == "two_panel" and c.trigger is not None:
        ph = int(OUT_W * 9 / 16)
        gap = 8
        img = Image.new("RGB", (OUT_W, ph * 2 + gap), DARK)
        img.paste(_fit(c.trigger, OUT_W, ph), (0, 0))
        img.paste(_fit(_crop_aspect(c.frame, _expand(c.moment.focus, 0.25), 16 / 9), OUT_W, ph), (0, ph + gap))
        return img, "two_panel"
    if layout == "zoom":
        x0, y0, x1, y1 = c.moment.focus
        fw, fh = (x1 - x0) * c.frame.width, (y1 - y0) * c.frame.height
        aspect = min(max(fw / max(fh, 1), 4 / 5), 4 / 3)
        img = _inside(c.frame, c.moment.focus, OUT_W, int(OUT_W / aspect))
        if fw < OUT_W * 0.5:  # starkes Hochskalieren leicht glätten
            img = img.filter(ImageFilter.SMOOTH_MORE)
        return img, "zoom"
    crop = _crop_aspect(c.frame, (0.02, 0.02, 0.98, 0.98), 4 / 3)
    return _fit(crop, OUT_W, int(OUT_W * 3 / 4)), "crop"


def render_meme(c: Candidate, caption: Caption, layout: str | None = None,
                rng: random.Random | None = None) -> tuple[Image.Image, str]:
    rng = rng or random.Random()
    fmt = caption.fmt if caption.fmt in FORMATS else "classic"
    if fmt == "compare":
        img = _render_compare(c, caption, rng)
        draw_stamp(img)
        return img, "compare"
    if fmt == "deepfry":
        img, used = _base(c, "crop")
        img = _render_deepfry(img, c, rng)
        draw_caption(img, caption.top, "top")
        if caption.bottom:
            draw_caption(img, caption.bottom, "bottom", max_lines=2)
        draw_stamp(img)
        return img, "deepfry"
    img, used = _base(c, layout or choose_layout(c))
    if fmt == "pov":
        img = draw_pov(img, caption.top, caption.bottom)
    else:
        lines = 2 if used == "two_panel" else 3
        draw_caption(img, caption.top, "top", max_lines=lines)
        if caption.bottom:
            draw_caption(img, caption.bottom, "bottom", max_lines=2)
    draw_stamp(img)
    return img, used if fmt == "classic" else f"{used}+pov"


def _render_compare(c: Candidate, caption: Caption, rng: random.Random) -> Image.Image:
    pw, ph = OUT_W // 2, int(OUT_W // 2 * 5 / 4)
    face = _inside(c.frame, c.moment.focus, pw, ph)
    other = reaction_image(caption.doodle, (pw, ph), rng)
    panels = Image.new("RGB", (OUT_W, ph), "white")
    panels.paste(face, (0, 0))
    panels.paste(other, (pw, 0))
    ImageDraw.Draw(panels).line((pw, 0, pw, ph), fill="black", width=6)
    for x, label in ((0, caption.left or "er im call:"), (pw, caption.right or "er innerlich:")):
        part = panels.crop((x, 0, x + pw, ph))
        draw_caption(part, label, "top", max_lines=2, scale=1.25)
        panels.paste(part, (x, 0))
    if caption.top:
        bh = _pov_height(caption.top, OUT_W) + 24
        band = draw_pov(Image.new("RGB", (OUT_W, bh), "white"), caption.top, "", boxes=False)
        out = Image.new("RGB", (OUT_W, band.height + ph), "white")
        out.paste(band, (0, 0))
        out.paste(panels, (0, band.height))
        return out
    return panels


def _render_deepfry(img: Image.Image, c: Candidate, rng: random.Random) -> Image.Image:
    W, H = img.size
    img = ImageEnhance.Color(img).enhance(2.6)
    img = ImageEnhance.Contrast(img).enhance(1.7)
    img = ImageEnhance.Sharpness(img).enhance(4.0)
    r, g, b = img.split()
    img = Image.merge("RGB", (r.point(lambda v: min(255, int(v * 1.15) + 10)), g, b.point(lambda v: int(v * 0.8))))
    for q in (12, 7):  # JPEG zerstören
        buf = io.BytesIO()
        img.save(buf, "JPEG", quality=q)
        img = Image.open(io.BytesIO(buf.getvalue())).convert("RGB")
    d = ImageDraw.Draw(img)
    # Roter Kreis um den spannendsten Bereich (Kachel)
    x0, y0, x1, y1 = c.moment.focus
    cx, cy = (x0 + x1) / 2 * W, (y0 + y1) / 2 * H
    rx, ry = max(60, (x1 - x0) * W * 0.4), max(60, (y1 - y0) * H * 0.4)
    for k in range(3):
        d.ellipse((cx - rx - k * 3, cy - ry - k * 2, cx + rx + k * 2, cy + ry + k * 3), outline=(255, 0, 0), width=7)
    base = img.convert("RGBA")
    for _ in range(rng.randint(2, 4)):
        sk = draw_skull(rng.randint(130, 230), rng)
        base.alpha_composite(sk, (rng.randint(0, W - sk.width), rng.randint(int(H * 0.25), H - sk.height)))
    return base.convert("RGB")


def _pov_height(text: str, W: int) -> int:
    img = Image.new("RGB", (W, 10))
    font = load_font(int(W * 0.06), kind="pov")
    lines = _wrap(ImageDraw.Draw(img), text.upper(), font, int(W * 0.86))
    return int(len(lines) * W * 0.06 * 1.32) + int(W * 0.03)


def draw_pov(img: Image.Image, top: str, bottom: str = "", boxes: bool = True) -> Image.Image:
    """TikTok-Stil: weisse fette Schrift auf schwarzen Boxen (oder schwarz auf weiss ohne Boxen)."""
    W, H = img.size
    d = ImageDraw.Draw(img)
    size = int(W * 0.06)
    font = load_font(size, kind="pov")
    for text, where in ((top, "top"), (bottom, "bottom")):
        if not text:
            continue
        lines = _wrap(d, " ".join(text.upper().split()), font, int(W * 0.86))[:4]
        lh = int(size * 1.32)
        y = int(W * 0.03) if where == "top" else H - lh * len(lines) - int(W * 0.09)
        for line in lines:
            tw = d.textlength(line, font=font)
            if boxes:
                d.rounded_rectangle((W / 2 - tw / 2 - 16, y - 4, W / 2 + tw / 2 + 16, y + lh - 2), radius=14, fill="black")
                d.text((W / 2, y + lh / 2 - 2), line, font=font, fill="white", anchor="mm")
            else:
                d.text((W / 2, y + lh / 2), line, font=font, fill="black", anchor="mm")
            y += lh
    return img


def _inside(img: Image.Image, box, w: int, h: int) -> Image.Image:
    """Schneidet exakt die Box (z. B. eine Kachel) aus und füllt w×h – ohne Nachbarkacheln."""
    W, H = img.size
    x0, y0, x1, y1 = box
    region = img.crop((int(x0 * W), int(y0 * H), max(int(x1 * W), int(x0 * W) + 2), max(int(y1 * H), int(y0 * H) + 2)))
    return ImageOps.fit(region, (w, h), Image.LANCZOS, centering=(0.5, 0.4))


def _expand(box, amount: float):
    x0, y0, x1, y1 = box
    return (max(0.0, x0 - amount), max(0.0, y0 - amount), min(1.0, x1 + amount), min(1.0, y1 + amount))


def render_silence(text: str = "15 Minuten und das Highlight war Stille.", sub: str = "") -> Image.Image:
    """Vorbereitete Grafik, wenn keine verwertbare Aufnahme vorliegt."""
    W = H = OUT_W
    img = Image.new("RGB", (W, H), GREY)
    d = ImageDraw.Draw(img)
    for i in range(0, W + H, 64):  # diagonale Streifen
        d.line((i, 0, i - H, H), fill=(49, 51, 56), width=22)
    logo = _logo()
    if logo is not None:
        lg = logo.resize((420, 420), Image.LANCZOS)
        base = img.convert("RGBA")
        base.alpha_composite(lg, ((W - 420) // 2, 330))
        img = base.convert("RGB")
    draw_caption(img, text, "top", max_lines=3)
    if sub:
        draw_caption(img, sub, "bottom", max_lines=2)
    draw_stamp(img)
    return img


def to_png(img: Image.Image) -> bytes:
    buf = io.BytesIO()
    img.save(buf, format="PNG", optimize=True)
    return buf.getvalue()


def to_jpeg(img: Image.Image, max_side: int = 1024, quality: int = 80) -> bytes:
    im = img.copy()
    im.thumbnail((max_side, max_side))
    buf = io.BytesIO()
    im.convert("RGB").save(buf, format="JPEG", quality=quality)
    return buf.getvalue()
