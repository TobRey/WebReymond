"""Bildquellen: Discord-Fenster, OBS, Beispielmaterial."""
from __future__ import annotations

from typing import Protocol

import numpy as np


class FrameSource(Protocol):
    name: str

    def grab(self) -> np.ndarray | None:  # RGB uint8, H x W x 3
        ...

    def close(self) -> None:
        ...
