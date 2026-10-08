#!/usr/bin/env python3
"""Test-Doppel einer CCAPI-Kamera – NUR für automatische Tests.

Bildet ausschliesslich das Verhalten nach, das in öffentlichen CCAPI-Clients
belegt ist (siehe docs/MACHBARKEIT.md): Manifest unter /ccapi, contents-Baum,
?kind=number/page, ?kind=info/thumbnail/display, Original ohne kind,
event/polling?continue=off mit "addedcontents", shooting/liveview + flip.
Keine CORS-Header (wie die echte Kamera). Wird nie mit der App ausgeliefert.

Steuerung für Tests:
  POST /mock/shoot?raw=1        neue Aufnahme (JPEG, optional + CR3)
  GET  /mock/log                 alle empfangenen Requests (z. B. DELETE-Prüfung)
"""
from __future__ import annotations

import argparse
import io
import json
import random
import threading
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import parse_qs, urlsplit

from PIL import Image, ImageDraw

VER = "ver100"
ROOT = f"/ccapi/{VER}"
PAGE = 100


def jpeg(w: int, h: int, hue: int, label: str, quality: int = 90) -> bytes:
    img = Image.new("RGB", (w, h), (hue % 256, (hue * 3) % 256, (hue * 7) % 256))
    d = ImageDraw.Draw(img)
    for i in range(0, w, max(8, w // 12)):
        d.line([(i, 0), (w - i, h)], fill=((hue * 5) % 256, 200, 255 - hue % 256), width=max(1, w // 200))
    d.rectangle([w // 10, h // 3, w * 9 // 10, h * 2 // 3], fill=(10, 10, 14))
    d.text((w // 8, h // 2 - 6), label, fill=(255, 255, 255))
    buf = io.BytesIO()
    img.save(buf, "JPEG", quality=quality)
    return buf.getvalue()


class MockFile:
    def __init__(self, folder: str, name: str, data: bytes, when: float, hue: int):
        self.folder, self.name, self.data, self.when, self.hue = folder, name, data, when, hue
        self._thumb = None
        self._display = None

    @property
    def path(self) -> str:
        return f"{ROOT}/contents/sd/{self.folder}/{self.name}"

    def thumb(self) -> bytes:
        if self._thumb is None:
            self._thumb = jpeg(160, 120, self.hue, self.name, 70)
        return self._thumb

    def display(self) -> bytes:
        if self._display is None:
            self._display = jpeg(1620, 1080, self.hue, self.name + " (display)", 85)
        return self._display


class Camera:
    def __init__(self, count: int, seed: int = 7):
        self.rng = random.Random(seed)
        self.lock = threading.Lock()
        self.files: list[MockFile] = []
        self.events: list[str] = []
        self.liveview = "off"
        self.frame = 0
        self.log: list[str] = []
        self.counter = 0
        self.break_next: dict[str, int] = {}
        self.events_on = True
        base = time.time() - 86400 * 3
        for i in range(count):
            folder = "100CANON" if i < count - 5 else "101CANON"
            self._add(folder, base + i * 60, raw=(i % 4 == 1), video=(i % 9 == 4))

    def _add(self, folder: str, when: float, raw: bool = False, video: bool = False) -> list[MockFile]:
        self.counter += 1
        n = self.counter
        hue = self.rng.randrange(256)
        added = []
        if video:
            data = b"\x00\x00\x00\x18ftypmp42" + self.rng.randbytes(900_000)
            added.append(MockFile(folder, f"MVI_{n:04d}.MP4", data, when, hue))
        else:
            added.append(MockFile(folder, f"IMG_{n:04d}.JPG", jpeg(2400, 1600, hue, f"IMG_{n:04d}"), when, hue))
            if raw:
                data = b"\x00\x00\x00\x18ftypcrx " + self.rng.randbytes(1_500_000)
                added.append(MockFile(folder, f"IMG_{n:04d}.CR3", data, when, hue))
        self.files.extend(added)
        return added

    def shoot(self, raw: bool) -> list[str]:
        with self.lock:
            added = self._add("101CANON", time.time(), raw=raw)
            self.events.extend(f.path for f in added)
            return [f.path for f in added]

    def find(self, path: str) -> MockFile | None:
        return next((f for f in self.files if f.path == path), None)


MANIFEST = {
    VER: [
        {"path": f"{ROOT}/deviceinformation", "get": True, "post": False, "put": False, "delete": False},
        {"path": f"{ROOT}/devicestatus/battery", "get": True, "post": False, "put": False, "delete": False},
        {"path": f"{ROOT}/contents", "get": True, "post": False, "put": False, "delete": True},
        {"path": f"{ROOT}/event/polling", "get": True, "post": False, "put": False, "delete": False},
        {"path": f"{ROOT}/shooting/liveview", "get": False, "post": True, "put": False, "delete": False},
        {"path": f"{ROOT}/shooting/liveview/flip", "get": True, "post": False, "put": False, "delete": False},
    ]
}


def make_handler(cam: Camera, opts):
    class H(BaseHTTPRequestHandler):
        protocol_version = "HTTP/1.1"

        def log_message(self, *a):
            pass

        def _json(self, obj, status=200):
            body = json.dumps(obj).encode()
            self.send_response(status)
            self.send_header("Content-Type", "application/json")
            self.send_header("Content-Length", str(len(body)))
            self.end_headers()
            self.wfile.write(body)

        def _blob(self, data: bytes, ctype: str, allow_range: bool = False, allow_break: bool = False):
            start, end, status = 0, len(data) - 1, 200
            rng = self.headers.get("Range")
            if allow_range and rng and rng.startswith("bytes="):
                a, _, b = rng[6:].partition("-")
                start = int(a or 0)
                end = int(b) if b else len(data) - 1
                if start >= len(data):
                    self.send_response(416)
                    self.send_header("Content-Range", f"bytes */{len(data)}")
                    self.send_header("Content-Length", "0")
                    self.end_headers()
                    return
                status = 206
            cut = cam.break_next.pop(urlsplit(self.path).path, None) if allow_break else None
            chunk = data[start:end + 1]
            self.send_response(status)
            self.send_header("Content-Type", ctype)
            self.send_header("Content-Length", str(len(chunk)))
            if status == 206:
                self.send_header("Content-Range", f"bytes {start}-{end}/{len(data)}")
            self.end_headers()
            step = 64 * 1024
            if cut is not None:
                # WLAN-Abriss simulieren: `cut` Bytes normal senden, dann Verbindung hart schliessen
                for i in range(0, cut, step):
                    if opts.rate:
                        time.sleep(step / opts.rate)
                    self.wfile.write(chunk[i:min(cut, i + step)])
                self.wfile.flush()
                time.sleep(0.3)
                self.connection.shutdown(2)
                self.close_connection = True
                return
            for i in range(0, len(chunk), step):
                if opts.rate:
                    time.sleep(step / opts.rate)
                try:
                    self.wfile.write(chunk[i:i + step])
                except (BrokenPipeError, ConnectionResetError):
                    return

        def _busy(self, msg: str):
            self._json({"message": msg}, 503)

        def do_DELETE(self):
            cam.log.append(f"DELETE {self.path}")
            self._json({})

        def do_POST(self):
            cam.log.append(f"POST {self.path}")
            u = urlsplit(self.path)
            q = parse_qs(u.query)
            length = int(self.headers.get("Content-Length") or 0)
            body = self.rfile.read(length) if length else b""
            if u.path == "/mock/events":
                cam.events_on = q.get("on", ["1"])[0] == "1"
                return self._json({})
            if u.path == "/mock/break":
                cam.break_next[q["path"][0]] = int(q.get("after", ["300000"])[0])
                return self._json({})
            if u.path == "/mock/shoot":
                return self._json({"added": cam.shoot(q.get("raw", ["0"])[0] == "1")})
            if u.path == f"{ROOT}/shooting/liveview":
                try:
                    payload = json.loads(body or b"{}")
                except ValueError:
                    return self._json({"message": "Invalid parameter"}, 400)
                size = payload.get("liveviewsize")
                if size not in ("off", "small", "medium"):
                    return self._json({"message": "Invalid parameter"}, 400)
                cam.liveview = size
                return self._json({})
            self._json({"message": "Not found"}, 404)

        def do_GET(self):
            rng = self.headers.get("Range")
            cam.log.append(f"GET {self.path}" + (f" [{rng}]" if rng else ""))
            u = urlsplit(self.path)
            q = {k: v[0] for k, v in parse_qs(u.query).items()}
            p = u.path.rstrip("/")
            if opts.latency:
                time.sleep(opts.latency)
            if p == "/mock/log":
                return self._json({"log": cam.log})
            if p == "/ccapi":
                return self._json(MANIFEST)
            if p == f"{ROOT}/deviceinformation":
                return self._json({"manufacturer": "Canon Inc.", "productname": "TEST-DOUBLE (kein echtes Gerät)",
                                   "guid": "00000000-0000-0000-0000-000000000000", "serialnumber": "MOCK0001",
                                   "firmwareversion": "0.0.0", "macaddress": "00:00:00:00:00:00"})
            if p == f"{ROOT}/devicestatus/battery":
                return self._json({"name": "LP-E12", "kind": "battery", "level": "half", "quality": "good"})
            if p == f"{ROOT}/event/polling":
                with cam.lock:
                    ev, cam.events = cam.events, []
                return self._json({"addedcontents": ev} if ev and cam.events_on else {})
            if p == f"{ROOT}/shooting/liveview/flip":
                if cam.liveview == "off":
                    return self._busy("Live view not started")
                cam.frame += 1
                w = 640 if cam.liveview == "small" else 1024
                return self._blob(jpeg(w, w * 2 // 3, cam.frame * 9, f"LIVE {cam.frame}", 60), "image/jpeg")
            if p == f"{ROOT}/contents":
                return self._json({"path": [f"{ROOT}/contents/sd"]})
            if p == f"{ROOT}/contents/sd":
                folders = sorted({f.folder for f in cam.files})
                return self._json({"path": [f"{ROOT}/contents/sd/{d}" for d in folders]})
            parts = p.split("/")
            if p.startswith(f"{ROOT}/contents/sd/") and len(parts) == 6:
                folder = parts[5]
                files = [f for f in cam.files if f.folder == folder]
                if not files:
                    return self._json({"message": "Not found"}, 404)
                if q.get("kind") == "number":
                    return self._json({"contentsnumber": len(files), "pagenumber": (len(files) + PAGE - 1) // PAGE})
                page = int(q.get("page", "1"))
                chunk = files[(page - 1) * PAGE: page * PAGE]
                return self._json({"path": [f.path for f in chunk]})
            f = cam.find(p)
            if f is None:
                return self._json({"message": "Not found"}, 404)
            kind = q.get("kind")
            if kind == "info":
                return self._json({"filesize": len(f.data), "protect": "disable", "archive": "disable",
                                   "rotate": "0", "rating": "off",
                                   "lastmodifieddate": time.strftime("%a, %d %b %Y %H:%M:%S", time.localtime(f.when))})
            if kind == "thumbnail":
                return self._blob(f.thumb(), "image/jpeg")
            if kind == "display":
                if f.name.endswith(".MP4"):
                    return self._json({"message": "Invalid parameter"}, 400)
                return self._blob(f.display(), "image/jpeg")
            if kind in (None, "main"):
                ctype = "image/jpeg" if f.name.endswith(".JPG") else "application/octet-stream"
                return self._blob(f.data, ctype, allow_range=opts.range, allow_break=True)
            return self._json({"message": "Invalid parameter"}, 400)

    return H


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--port", type=int, default=18080)
    ap.add_argument("--count", type=int, default=40)
    ap.add_argument("--range", action="store_true", help="Range-Header unterstützen")
    ap.add_argument("--rate", type=int, default=0, help="Bytes/s Drosselung für Originale")
    ap.add_argument("--latency", type=float, default=0.0)
    opts = ap.parse_args()
    cam = Camera(opts.count)
    srv = ThreadingHTTPServer(("127.0.0.1", opts.port), make_handler(cam, opts))
    print(f"MOCK-CCAPI auf http://127.0.0.1:{opts.port} ({len(cam.files)} Dateien)", flush=True)
    srv.serve_forever()


if __name__ == "__main__":
    main()
