"""Momentbewertung und Kandidatenpool.

Pro analysiertem Frame entsteht ein `Moment` mit Punktzahl und Signalen:
Lachen, Grimassen, auffällige Gesten, mehrere gleichzeitige Reaktionen,
überraschende Bildwechsel und laute Ausbrüche. Der Pool behält pro Block nur
eine kleine Auswahl der besten Kandidaten (Standard: 5) im Arbeitsspeicher.
Beim Blockwechsel wird alles verworfen.
"""
from __future__ import annotations

import threading
import time
from collections import deque
from dataclasses import dataclass, field

import numpy as np
from PIL import Image

try:  # OpenCV ist optional – ohne läuft die Bewegungsanalyse mit numpy.
    import cv2  # type: ignore
except Exception:  # pragma: no cover
    cv2 = None

from .audio import AudioFeatures

GRID = (3, 3)
MAX_FRAME_W = 1280
SIGNAL_WEIGHTS = {
    "laugh": 3.0,
    "smile": 1.2,
    "grimace": 1.5,
    "gesture": 1.0,
    "multi": 2.0,
    "scene": 1.5,
    "loud": 1.2,
}


@dataclass
class Moment:
    t: float
    score: float
    signals: dict[str, float]
    focus: tuple[float, float, float, float]  # normiert: x0, y0, x1, y1
    active_cells: int = 0
    faces: int = 0

    @property
    def dominant(self) -> str:
        if not self.signals:
            return "dry"
        name, value = max(self.signals.items(), key=lambda kv: kv[1] * SIGNAL_WEIGHTS.get(kv[0], 1.0))
        return name if value > 0.15 else "dry"


@dataclass
class Candidate:
    moment: Moment
    frame: Image.Image
    trigger: Image.Image | None = None      # Frame ~2,5 s vor dem Höhepunkt (2-Panel)
    audio: np.ndarray | None = None          # nur bei aktivierter Transkription
    id: int = 0

    @property
    def score(self) -> float:
        return self.moment.score


def _to_pil(frame: np.ndarray) -> Image.Image:
    img = Image.fromarray(frame)
    if img.width > MAX_FRAME_W:
        h = round(img.height * MAX_FRAME_W / img.width)
        img = img.resize((MAX_FRAME_W, h), Image.LANCZOS)
    return img


class CandidatePool:
    """Thread-sicherer Pool der besten Momente eines Blocks."""

    def __init__(self, keep: int = 5, min_score: float = 0.9, dedupe_s: float = 3.0):
        self.keep = keep
        self.min_score = min_score
        self.dedupe_s = dedupe_s
        self._lock = threading.Lock()
        self._items: list[Candidate] = []
        self._fallback: Candidate | None = None   # bester Frame überhaupt (für trockene Memes)
        self._counter = 0
        self.version = 0
        self.frames_seen = 0

    # Prüfen, bevor ein (teures) PIL-Bild erzeugt wird.
    def wants(self, moment: Moment) -> tuple[bool, bool]:
        with self._lock:
            as_candidate = False
            if moment.score >= self.min_score:
                near = [c for c in self._items if abs(c.moment.t - moment.t) < self.dedupe_s]
                if near:
                    as_candidate = moment.score > max(c.score for c in near)
                else:
                    as_candidate = len(self._items) < self.keep or moment.score > self._items[-1].score
            as_fallback = self._fallback is None or moment.score > self._fallback.score or (
                moment.t - self._fallback.moment.t > 60 and moment.score >= self._fallback.score * 0.8
            )
            return as_candidate, as_fallback

    def offer(self, moment: Moment, frame: np.ndarray, trigger: np.ndarray | None = None,
              audio: np.ndarray | None = None) -> bool:
        with self._lock:
            self.frames_seen += 1
        as_candidate, as_fallback = self.wants(moment)
        if not (as_candidate or as_fallback):
            return False
        cand = Candidate(moment=moment, frame=_to_pil(frame),
                         trigger=_to_pil(trigger) if trigger is not None else None, audio=audio)
        with self._lock:
            self._counter += 1
            cand.id = self._counter
            if as_fallback:
                self._fallback = cand
            if as_candidate:
                self._items = [c for c in self._items if abs(c.moment.t - moment.t) >= self.dedupe_s]
                self._items.append(cand)
                self._items.sort(key=lambda c: c.score, reverse=True)
                del self._items[self.keep:]
                self.version += 1
            return as_candidate

    def add_candidate(self, cand: Candidate) -> None:
        """Für Tests: fertigen Kandidaten direkt einfügen."""
        with self._lock:
            self._counter += 1
            cand.id = cand.id or self._counter
            self._items.append(cand)
            self._items.sort(key=lambda c: c.score, reverse=True)
            del self._items[self.keep:]
            if self._fallback is None or cand.score > self._fallback.score:
                self._fallback = cand
            self.version += 1

    def snapshot(self) -> tuple[list[Candidate], Candidate | None]:
        with self._lock:
            return list(self._items), self._fallback

    def best_score(self) -> float:
        with self._lock:
            return self._items[0].score if self._items else 0.0

    def rotate(self) -> int:
        """Blockwechsel: alle Kandidaten verwerfen. Gibt die Anzahl verworfener zurück."""
        with self._lock:
            n = len(self._items) + (1 if self._fallback else 0)
            self._items = []
            self._fallback = None
            self.frames_seen = 0
            self.version += 1
            return n


def _bands(dark_frac: np.ndarray, min_len: int) -> list[tuple[int, int]]:
    """Zusammenhängende Bereiche, die NICHT (fast) komplett dunkel sind."""
    content = dark_frac < 0.85
    out, start = [], None
    for i, c in enumerate(list(content) + [False]):
        if c and start is None:
            start = i
        elif not c and start is not None:
            if i - start >= min_len:
                out.append((start, i))
            start = None
    return out


def detect_tiles(gray: np.ndarray, dark: float = 0.15) -> list[tuple[float, float, float, float]]:
    """Findet Webcam-Kacheln im Discord-Raster anhand der dunklen Fugen.

    `gray` ist ein kleines Graubild (0..1). Ergebnis: normierte Boxen.
    """
    h, w = gray.shape
    is_dark = gray < dark
    tiles: list[tuple[float, float, float, float]] = []
    for r0, r1 in _bands(is_dark.mean(axis=1), max(4, h // 12)):
        band = is_dark[r0:r1]
        for c0, c1 in _bands(band.mean(axis=0), max(4, w // 12)):
            sub = is_dark[r0:r1, c0:c1]
            for rr0, rr1 in _bands(sub.mean(axis=1), max(4, h // 12)):
                tiles.append((c0 / w, (r0 + rr0) / h, c1 / w, (r0 + rr1) / h))
    if not 2 <= len(tiles) <= 16:
        return []
    return tiles


class FrameAnalyzer:
    """Leichtgewichtige Signalanalyse pro Frame (läuft lokal, ca. 4 fps)."""

    def __init__(self, grid: tuple[int, int] = GRID, use_faces: bool = True):
        self.grid = grid
        self.prev: np.ndarray | None = None
        self.scene_base = 0.01
        self.cell_base = np.full(grid[0] * grid[1], 0.01, dtype=np.float32)
        self.face_cascade = None
        self.smile_cascade = None
        self.prev_faces: list[tuple[int, int, int, int]] = []
        self.tiles: list[tuple[float, float, float, float]] = []
        self._tiles_at = 0.0
        if use_faces and cv2 is not None:
            try:
                base = cv2.data.haarcascades
                self.face_cascade = cv2.CascadeClassifier(base + "haarcascade_frontalface_default.xml")
                self.smile_cascade = cv2.CascadeClassifier(base + "haarcascade_smile.xml")
                if self.face_cascade.empty():
                    self.face_cascade = None
                if self.smile_cascade is not None and self.smile_cascade.empty():
                    self.smile_cascade = None
            except Exception:
                self.face_cascade = self.smile_cascade = None

    def reset(self) -> None:
        self.prev = None
        self.prev_faces = []
        self.tiles = []
        self._tiles_at = 0.0

    def _regions(self, small: np.ndarray, t: float) -> list[tuple[float, float, float, float]]:
        if t - self._tiles_at > 2.0:
            found = detect_tiles(small)
            if found != self.tiles:
                self.tiles = found
                self.cell_base = np.full(max(1, len(found)) if found else self.grid[0] * self.grid[1], 0.01, dtype=np.float32)
            self._tiles_at = t
        if self.tiles:
            return self.tiles
        gh, gw = self.grid
        return [(c / gw, r / gh, (c + 1) / gw, (r + 1) / gh) for r in range(gh) for c in range(gw)]

    @staticmethod
    def _gray_small(frame: np.ndarray, w: int = 192) -> np.ndarray:
        h = max(1, round(frame.shape[0] * w / frame.shape[1]))
        if cv2 is not None:
            g = cv2.cvtColor(frame, cv2.COLOR_RGB2GRAY)
            g = cv2.resize(g, (w, h), interpolation=cv2.INTER_AREA)
            return cv2.GaussianBlur(g, (3, 3), 0).astype(np.float32) / 255.0
        img = Image.fromarray(frame).convert("L").resize((w, h))
        return np.asarray(img, dtype=np.float32) / 255.0

    def analyze(self, frame: np.ndarray, t: float | None = None, audio: AudioFeatures | None = None) -> Moment:
        t = time.time() if t is None else t
        small = self._gray_small(frame)
        signals: dict[str, float] = {}
        h, w = small.shape
        focus = (0.0, 0.0, 1.0, 1.0)
        active = 0

        if self.prev is not None and self.prev.shape == small.shape:
            diff = np.abs(small - self.prev)
            scene = float(diff.mean())
            signals["scene"] = float(np.clip((scene - 3.0 * self.scene_base - 0.02) / 0.12, 0, 1))
            self.scene_base = 0.97 * self.scene_base + 0.03 * min(scene, 0.2)

            regions = self._regions(small, t)
            if len(self.cell_base) != len(regions):
                self.cell_base = np.full(len(regions), 0.01, dtype=np.float32)
            cells = np.zeros(len(regions), dtype=np.float32)
            for i, (x0, y0, x1, y1) in enumerate(regions):
                cell = diff[int(y0 * h):max(int(y1 * h), int(y0 * h) + 1), int(x0 * w):max(int(x1 * w), int(x0 * w) + 1)]
                cells[i] = float(cell.mean())
            excess = cells - np.maximum(0.025, 2.5 * self.cell_base)
            active = int((excess > 0).sum())
            signals["gesture"] = float(np.clip(excess.max() / 0.08, 0, 1))
            signals["multi"] = float(np.clip((active - 1) / 3.0, 0, 1)) if signals["scene"] < 0.6 else 0.0
            self.cell_base = 0.95 * self.cell_base + 0.05 * np.minimum(cells, 0.15)
            focus = regions[int(cells.argmax())]
            if active >= 3:
                focus = (0.0, 0.0, 1.0, 1.0)
        self.prev = small

        faces = self._faces(frame, signals)
        if faces and signals.get("smile", 0) > 0:
            focus = faces[0]

        if audio is not None:
            signals["loud"] = audio.loud
            signals["laugh"] = float(np.clip(audio.laugh * (0.6 + 0.4 * max(signals.get("smile", 0), signals.get("gesture", 0))), 0, 1))
        score = sum(SIGNAL_WEIGHTS.get(k, 1.0) * v for k, v in signals.items())
        # Gleichzeitige Signale verstärken sich (Lachen + Bewegung + Bildwechsel).
        strong = sum(1 for v in signals.values() if v > 0.4)
        score *= 1.0 + 0.15 * max(0, strong - 1)
        return Moment(t=t, score=round(score, 3), signals=signals, focus=focus, active_cells=active, faces=len(faces))

    def _faces(self, frame: np.ndarray, signals: dict[str, float]) -> list[tuple[float, float, float, float]]:
        if self.face_cascade is None or cv2 is None:
            return []
        H, W = frame.shape[:2]
        scale = 480 / W if W > 480 else 1.0
        gray = cv2.cvtColor(frame, cv2.COLOR_RGB2GRAY)
        if scale != 1.0:
            gray = cv2.resize(gray, (480, round(H * scale)), interpolation=cv2.INTER_AREA)
        found = self.face_cascade.detectMultiScale(gray, scaleFactor=1.15, minNeighbors=5, minSize=(28, 28))
        out = []
        smiles = 0
        grimace = 0.0
        for (x, y, fw, fh) in found:
            if self.smile_cascade is not None:
                roi = gray[y + fh // 2:y + fh, x:x + fw]
                if roi.size and len(self.smile_cascade.detectMultiScale(roi, 1.7, 20)):
                    smiles += 1
            # Grimasse: Gesichtsbox ändert sich stark gegenüber dem Vorframe
            for (px, py, pw, ph) in self.prev_faces:
                if abs(px - x) < fw and abs(py - y) < fh:
                    grimace = max(grimace, min(1.0, abs(pw - fw) / max(fw, 1) * 4 + abs(ph - fh) / max(fh, 1) * 4))
            gx, gy = gray.shape[1], gray.shape[0]
            pad = 0.6
            out.append((max(0.0, (x - fw * pad) / gx), max(0.0, (y - fh * pad) / gy),
                        min(1.0, (x + fw * (1 + pad)) / gx), min(1.0, (y + fh * (1 + pad)) / gy)))
        self.prev_faces = [tuple(map(int, f)) for f in found]
        if len(found):
            signals["smile"] = min(1.0, smiles / len(found) + 0.25 * max(0, smiles - 1))
            signals["grimace"] = grimace
        return out


class FrameHistory:
    """Kurzer Ringpuffer (wenige Sekunden) für den Auslöser-Frame eines 2-Panel-Memes."""

    def __init__(self, seconds: float = 4.0):
        self.seconds = seconds
        self._buf: deque[tuple[float, np.ndarray]] = deque()
        self._lock = threading.Lock()

    def push(self, t: float, frame: np.ndarray) -> None:
        with self._lock:
            self._buf.append((t, frame))
            while self._buf and t - self._buf[0][0] > self.seconds:
                self._buf.popleft()

    def before(self, t: float, delta: float = 2.5) -> np.ndarray | None:
        with self._lock:
            best = None
            for ts, fr in self._buf:
                if ts <= t - delta:
                    best = fr
            if best is None and self._buf and self._buf[0][0] < t - 0.8:
                best = self._buf[0][1]
            return best

    def clear(self) -> None:
        with self._lock:
            self._buf.clear()


@dataclass
class AnalysisStats:
    frames: int = 0
    last_score: float = 0.0
    last_signals: dict[str, float] = field(default_factory=dict)
