"""Erfasst die sichtbare Ansicht eines Fensters (z. B. Discord) unter Windows.

Es wird der Bildschirmbereich des Fensters per `mss` aufgenommen. Das Fenster
muss sichtbar (nicht minimiert, nicht verdeckt) sein – genau die Ansicht, die
du im Call siehst.
"""
from __future__ import annotations

import ctypes
import sys
from dataclasses import dataclass

import numpy as np

IS_WINDOWS = sys.platform.startswith("win")


@dataclass
class WindowInfo:
    hwnd: int
    title: str

    def label(self) -> str:
        return f"{self.title}  [#{self.hwnd}]"


def enable_dpi_awareness() -> None:
    if not IS_WINDOWS:
        return
    try:
        ctypes.windll.shcore.SetProcessDpiAwareness(2)  # per-monitor
    except Exception:
        try:
            ctypes.windll.user32.SetProcessDPIAware()
        except Exception:
            pass


def list_windows(filter_text: str = "") -> list[WindowInfo]:
    if not IS_WINDOWS:
        return []
    user32 = ctypes.windll.user32
    out: list[WindowInfo] = []
    proto = ctypes.WINFUNCTYPE(ctypes.c_bool, ctypes.c_void_p, ctypes.c_void_p)

    def cb(hwnd, _lparam):
        if not user32.IsWindowVisible(hwnd):
            return True
        length = user32.GetWindowTextLengthW(hwnd)
        if length == 0:
            return True
        buf = ctypes.create_unicode_buffer(length + 1)
        user32.GetWindowTextW(hwnd, buf, length + 1)
        title = buf.value
        if not filter_text or filter_text.lower() in title.lower():
            out.append(WindowInfo(int(hwnd), title))
        return True

    user32.EnumWindows(proto(cb), 0)
    return out


def window_rect(hwnd: int) -> tuple[int, int, int, int] | None:
    if not IS_WINDOWS:
        return None
    user32 = ctypes.windll.user32
    if user32.IsIconic(hwnd) or not user32.IsWindow(hwnd):
        return None

    class RECT(ctypes.Structure):
        _fields_ = [("left", ctypes.c_long), ("top", ctypes.c_long), ("right", ctypes.c_long), ("bottom", ctypes.c_long)]

    rect = RECT()
    # Client-Bereich ohne Fensterrahmen
    if not user32.GetClientRect(hwnd, ctypes.byref(rect)):
        return None

    class POINT(ctypes.Structure):
        _fields_ = [("x", ctypes.c_long), ("y", ctypes.c_long)]

    pt = POINT(0, 0)
    user32.ClientToScreen(hwnd, ctypes.byref(pt))
    w, h = rect.right - rect.left, rect.bottom - rect.top
    if w < 50 or h < 50:
        return None
    return pt.x, pt.y, w, h


class WindowCapture:
    def __init__(self, hwnd: int, title: str = ""):
        self.hwnd = hwnd
        self.name = f"Fenster: {title or hwnd}"
        self._sct = None

    def grab(self) -> np.ndarray | None:
        rect = window_rect(self.hwnd)
        if rect is None:
            return None
        if self._sct is None:
            import mss  # type: ignore

            self._sct = mss.mss()
        x, y, w, h = rect
        shot = self._sct.grab({"left": x, "top": y, "width": w, "height": h})
        arr = np.asarray(shot)[:, :, :3][:, :, ::-1]  # BGRA -> RGB
        return np.ascontiguousarray(arr)

    def close(self) -> None:
        if self._sct is not None:
            try:
                self._sct.close()
            except Exception:
                pass
            self._sct = None
