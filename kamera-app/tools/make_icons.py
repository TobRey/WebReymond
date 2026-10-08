#!/usr/bin/env python3
"""Erzeugt die App-Icons (PNG + SVG) für Kamera-Link."""
import math
from pathlib import Path

from PIL import Image, ImageDraw

OUT = Path(__file__).resolve().parents[1] / "web" / "assets" / "icons"
A, B = (255, 77, 141), (255, 165, 61)


def master(size=1024):
    img = Image.new("RGB", (size, size))
    px = img.load()
    for y in range(size):
        for x in range(size):
            t = (x + y) / (2 * size)
            px[x, y] = tuple(int(A[i] + (B[i] - A[i]) * t) for i in range(3))
    d = ImageDraw.Draw(img)
    c, r, w = size / 2, size * 0.30, int(size * 0.055)
    d.ellipse([c - r, c - r, c + r, c + r], outline="white", width=w)
    for k in range(6):
        a = math.radians(60 * k - 30)
        x1, y1 = c + r * math.cos(a), c + r * math.sin(a)
        a2 = a + math.radians(100)
        x2, y2 = c + r * 0.45 * math.cos(a2), c + r * 0.45 * math.sin(a2)
        d.line([x1, y1, x2, y2], fill="white", width=w)
    return img


def main():
    OUT.mkdir(parents=True, exist_ok=True)
    m = master()
    for name, s in (("icon-512.png", 512), ("icon-192.png", 192), ("apple-touch-icon.png", 180)):
        m.resize((s, s), Image.LANCZOS).save(OUT / name, optimize=True)
    (OUT / "icon.svg").write_text(
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">'
        '<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#ff4d8d"/>'
        '<stop offset="1" stop-color="#ffa53d"/></linearGradient></defs>'
        '<rect width="24" height="24" rx="6" fill="url(#g)"/>'
        '<g fill="none" stroke="#fff" stroke-width="1.6" stroke-linecap="round">'
        '<circle cx="12" cy="12" r="7"/><path d="M14 5.3L9.6 12.9M18.7 9.7h-8.6M16.6 17.3L12.4 10M10 18.7l4.3-7.5M5.3 14.3h8.6M7.4 6.7l4.3 7.5"/>'
        '</g></svg>\n', "utf-8")
    print("Icons erzeugt in", OUT)


if __name__ == "__main__":
    main()
