"""QR-Generator der Brücke gegen einen unabhängigen Decoder (zxing-cpp) prüfen."""
import sys
from pathlib import Path

import zxingcpp
from PIL import Image

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "bridge"))
from kamera_bruecke import qr_matrix  # noqa: E402

CASES = ["http://1.2.3.4:8765/", "http://192.168.1.23:8765/", "https://192.168.100.123:8766/",
         "http://10.0.0.5:8765/", "http://kamera-macbook-pro.local:8765/",
         "https://very-long-hostname-of-some-computer-at-home.local:8766/ab"]


def render(m, scale=6, quiet=4):
    n = len(m)
    img = Image.new("L", ((n + 2 * quiet) * scale,) * 2, 255)
    px = img.load()
    for y in range(n):
        for x in range(n):
            if m[y][x]:
                for dy in range(scale):
                    for dx in range(scale):
                        px[(x + quiet) * scale + dx, (y + quiet) * scale + dy] = 0
    return img


def test_qr_roundtrip():
    for text in CASES:
        res = zxingcpp.read_barcodes(render(qr_matrix(text)))
        assert res and res[0].text == text, (text, [r.text for r in res])


if __name__ == "__main__":
    test_qr_roundtrip()
    print("QR ok:", len(CASES), "Fälle")
