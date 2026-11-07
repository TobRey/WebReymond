"""OBS als Bildquelle (optional, MVP-Alternative).

OBS 28+ hat obs-websocket eingebaut (Werkzeuge → WebSocket-Servereinstellungen).
Lege in OBS eine Fensteraufnahme-Quelle für Discord an und trage ihren Namen
als OBS_SOURCE ein. Der Ton kommt weiterhin per Loopback.
"""
from __future__ import annotations

import base64
import io

import numpy as np
from PIL import Image


class ObsCapture:
    def __init__(self, host: str, port: int, password: str, source: str, width: int = 1280):
        import obsws_python as obs  # type: ignore

        self.client = obs.ReqClient(host=host, port=port, password=password or None, timeout=3)
        self.source = source
        self.width = width
        self.name = f"OBS: {source}"

    def grab(self) -> np.ndarray | None:
        try:
            resp = self.client.get_source_screenshot(self.source, "jpg", self.width, None, 85)
        except Exception:
            return None
        data = resp.image_data.split(",", 1)[-1]
        img = Image.open(io.BytesIO(base64.b64decode(data))).convert("RGB")
        return np.asarray(img)

    def close(self) -> None:
        try:
            self.client.disconnect()
        except Exception:
            pass
