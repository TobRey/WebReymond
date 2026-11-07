"""Beispielmaterial für den Testmodus.

`SampleCallSource` zeichnet einen synthetischen Videocall mit vier Comic-Figuren
(keine echten Personen). In zufälligen, aber reproduzierbaren Abständen lachen
sie, staunen, winken oder schalten die Kamera ab. Der passende Ton kommt aus
`sample_audio_levels`. Alternativ spielt `FileSource` ein Video oder einen
Bilderordner ab.
"""
from __future__ import annotations

import math
import random
import time
from dataclasses import dataclass
from pathlib import Path

import numpy as np
from PIL import Image, ImageDraw

from ..fonts import load_font

NAMES = ["Alex", "Sam", "Robin", "Kim"]
SKIN = [(246, 206, 170), (214, 160, 120), (170, 115, 80), (240, 190, 150)]
TILE_BG = [(64, 70, 120), (90, 60, 110), (50, 95, 90), (110, 80, 60)]
SLOT = 7.0


@dataclass
class Event:
    kind: str           # laugh | surprise | wave | camoff
    who: tuple[int, ...]
    start: float
    end: float


def events_for_slot(slot: int, seed: int = 15) -> list[Event]:
    rng = random.Random(slot * 7919 + seed)
    out: list[Event] = []
    if slot % 4 == 3:  # regelmässiger Höhepunkt: alle lachen
        st = slot * SLOT + rng.uniform(1, 3)
        out.append(Event("laugh", (0, 1, 2, 3), st, st + rng.uniform(2.0, 3.0)))
        return out
    if rng.random() < 0.75:
        kind = rng.choice(["laugh", "surprise", "wave", "laugh", "camoff"])
        n = 1 if kind in ("wave", "camoff") else rng.choice([1, 1, 2])
        who = tuple(sorted(rng.sample(range(4), n)))
        st = slot * SLOT + rng.uniform(0.5, 3.5)
        out.append(Event(kind, who, st, st + rng.uniform(1.5, 3.0)))
    return out


def active_events(t_rel: float, seed: int = 15) -> list[Event]:
    slot = int(t_rel // SLOT)
    evs = events_for_slot(slot - 1, seed) + events_for_slot(slot, seed)
    return [e for e in evs if e.start <= t_rel < e.end]


class SampleClock:
    def __init__(self, seed: int = 15):
        self.t0 = time.time()
        self.seed = seed

    def rel(self, t: float | None = None) -> float:
        return (time.time() if t is None else t) - self.t0


def sample_audio_levels(clock: SampleClock):
    def levels(t: float) -> tuple[float, float]:
        evs = active_events(clock.rel(t), clock.seed)
        laugh = sum(len(e.who) for e in evs if e.kind == "laugh") / 4.0
        talk = 0.6 if any(e.kind in ("surprise", "wave") for e in evs) else 0.3
        return min(1.0, laugh), talk
    return levels


class SampleCallSource:
    def __init__(self, clock: SampleClock | None = None, size: tuple[int, int] = (1280, 720)):
        self.clock = clock or SampleClock()
        self.size = size
        self.name = "Beispielmaterial (synthetischer Call)"
        self.font = load_font(22)

    def grab(self) -> np.ndarray:
        W, H = self.size
        t = self.clock.rel()
        evs = active_events(t, self.clock.seed)
        img = Image.new("RGB", (W, H), (30, 31, 34))
        d = ImageDraw.Draw(img)
        gap = 12
        tw, th = (W - 3 * gap) // 2, (H - 3 * gap) // 2
        for i in range(4):
            x0 = gap + (i % 2) * (tw + gap)
            y0 = gap + (i // 2) * (th + gap)
            state = {e.kind for e in evs if i in e.who}
            self._tile(d, img, i, (x0, y0, x0 + tw, y0 + th), state, t)
        return np.asarray(img)

    def _tile(self, d: ImageDraw.ImageDraw, img: Image.Image, i: int, box, state: set[str], t: float) -> None:
        x0, y0, x1, y1 = box
        if "camoff" in state:
            d.rounded_rectangle(box, 14, fill=(43, 45, 49))
            cx, cy = (x0 + x1) // 2, (y0 + y1) // 2
            d.ellipse((cx - 50, cy - 50, cx + 50, cy + 50), fill=(88, 101, 242))
            d.text((cx, cy), NAMES[i][0], font=load_font(56), fill="white", anchor="mm")
            d.text((x0 + 14, y1 - 34), NAMES[i], font=self.font, fill="white")
            return
        d.rounded_rectangle(box, 14, fill=TILE_BG[i])
        cx = (x0 + x1) // 2 + int(6 * math.sin(t * 0.9 + i))
        cy = (y0 + y1) // 2 + 10 + int(4 * math.sin(t * 1.3 + i * 2))
        if "laugh" in state:
            cx += int(10 * math.sin(t * 25 + i))
            cy += int(6 * math.sin(t * 19 + i))
        r = min(x1 - x0, y1 - y0) // 3
        d.ellipse((cx - r, cy - r, cx + r, cy + int(r * 1.1)), fill=SKIN[i])
        # Haare
        d.chord((cx - r, cy - r - 8, cx + r, cy + r // 2), 180, 360, fill=[(60, 40, 30), (20, 20, 20), (150, 90, 40), (90, 50, 120)][i])
        ey = cy - r // 5
        blink = (t * 0.7 + i) % 4 < 0.12
        if "laugh" in state:
            for ex in (cx - r // 3, cx + r // 3):
                d.arc((ex - 16, ey - 8, ex + 16, ey + 14), 200, 340, fill=(30, 30, 30), width=5)
            d.chord((cx - r // 2, cy + 5, cx + r // 2, cy + r // 1.4), 0, 180, fill=(120, 20, 40))
        elif "surprise" in state:
            for ex in (cx - r // 3, cx + r // 3):
                d.ellipse((ex - 14, ey - 14, ex + 14, ey + 14), fill="white", outline=(30, 30, 30), width=3)
                d.ellipse((ex - 5, ey - 5, ex + 5, ey + 5), fill=(30, 30, 30))
            d.ellipse((cx - 18, cy + r // 3, cx + 18, cy + r // 3 + 40), fill=(90, 20, 30))
        else:
            for ex in (cx - r // 3, cx + r // 3):
                if blink:
                    d.line((ex - 10, ey, ex + 10, ey), fill=(30, 30, 30), width=4)
                else:
                    d.ellipse((ex - 8, ey - 8, ex + 8, ey + 8), fill=(30, 30, 30))
            talk = abs(math.sin(t * 6 + i)) * 10 if (int(t / 3) + i) % 4 == 0 else 2
            d.ellipse((cx - 28, cy + r // 3, cx + 28, cy + r // 3 + 6 + talk), fill=(110, 40, 50))
        if "wave" in state:
            hx = cx + r + 30 + int(25 * math.sin(t * 12))
            hy = cy - r // 2
            d.line((cx + r - 10, cy + r, hx, hy + 30), fill=SKIN[i], width=22)
            d.ellipse((hx - 26, hy - 26, hx + 26, hy + 26), fill=SKIN[i])
        d.text((x0 + 14, y1 - 34), NAMES[i], font=self.font, fill="white")
        if "laugh" in state or "surprise" in state:
            d.rounded_rectangle(box, 14, outline=(35, 165, 90), width=4)  # Discord-Sprechrahmen

    def close(self) -> None:
        pass


class FileSource:
    """Spielt ein Video (mp4/webm …) oder einen Ordner mit Bildern in Endlosschleife ab."""

    def __init__(self, path: str, fps: float = 4.0):
        self.path = Path(path)
        self.name = f"Beispieldatei: {self.path.name}"
        self.fps = fps
        self._images: list[Path] = []
        self._idx = 0
        self._cap = None
        if self.path.is_dir():
            self._images = sorted(p for p in self.path.iterdir() if p.suffix.lower() in {".png", ".jpg", ".jpeg", ".webp"})
        else:
            import cv2  # type: ignore

            self._cap = cv2.VideoCapture(str(self.path))
            src_fps = self._cap.get(cv2.CAP_PROP_FPS) or 25
            self._skip = max(1, int(round(src_fps / fps)))

    def grab(self) -> np.ndarray | None:
        if self._images:
            img = Image.open(self._images[(self._idx // max(1, int(self.fps * 3))) % len(self._images)]).convert("RGB")
            self._idx += 1
            return np.asarray(img)
        if self._cap is None:
            return None
        import cv2  # type: ignore

        frame = None
        for _ in range(self._skip):
            ok, frame = self._cap.read()
            if not ok:
                self._cap.set(cv2.CAP_PROP_POS_FRAMES, 0)
                ok, frame = self._cap.read()
                if not ok:
                    return None
        return cv2.cvtColor(frame, cv2.COLOR_BGR2RGB)

    def close(self) -> None:
        if self._cap is not None:
            self._cap.release()
