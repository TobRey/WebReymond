"""Integrationstests der Kamera-Brücke gegen das CCAPI-Test-Doppel (tools/mock_ccapi.py).

Start: python3 -m unittest tests/test_bridge.py  (aus dem Ordner kamera-app, nach tools/build.py)
"""
from __future__ import annotations

import hashlib
import http.client
import io
import json
import socket
import ssl
import subprocess
import sys
import tempfile
import time
import unittest
import urllib.request
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
BRIDGE = ROOT / "dist" / "kamera-bruecke.py"
MOCK = ROOT / "tools" / "mock_ccapi.py"


def free_port() -> int:
    with socket.socket() as s:
        s.bind(("127.0.0.1", 0))
        return s.getsockname()[1]


def wait_port(port: int, timeout=10.0):
    end = time.time() + timeout
    while time.time() < end:
        try:
            with socket.create_connection(("127.0.0.1", port), timeout=0.3):
                return
        except OSError:
            time.sleep(0.1)
    raise RuntimeError(f"Port {port} nicht erreichbar")


class Env:
    def __init__(self, mock_range: bool, https: bool = False):
        self.mock_port = free_port()
        self.port = free_port()
        while self.port + 1 == self.mock_port:
            self.port = free_port()
        self.tmp = tempfile.TemporaryDirectory()
        args = [sys.executable, str(MOCK), "--port", str(self.mock_port), "--count", "12"]
        if mock_range:
            args.append("--range")
        self.mock = subprocess.Popen(args, stdout=subprocess.DEVNULL)
        wait_port(self.mock_port)
        bargs = [sys.executable, str(BRIDGE), "--camera", f"127.0.0.1:{self.mock_port}", "--port", str(self.port),
                 "--data-dir", self.tmp.name, "--no-qr", "--scan-extra", f"127.0.0.1:{self.mock_port}"]
        if https:
            bargs.append("--https")
        self.bridge = subprocess.Popen(bargs, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        wait_port(self.port)
        if https:
            wait_port(self.port + 1)

    def close(self):
        for p in (self.bridge, self.mock):
            p.terminate()
            p.wait(5)
        self.tmp.cleanup()

    def req(self, method, path, headers=None, body=None, port=None):
        conn = http.client.HTTPConnection("127.0.0.1", port or self.port, timeout=20)
        conn.request(method, path, body=body, headers=headers or {})
        r = conn.getresponse()
        data = r.read()
        conn.close()
        return r, data

    def mock_get(self, path):
        with urllib.request.urlopen(f"http://127.0.0.1:{self.mock_port}{path}") as r:
            return r.read()

    def files(self):
        _, d = self.req("GET", "/cam/ccapi/ver100/contents/sd")
        out = []
        for folder in json.loads(d)["path"]:
            _, n = self.req("GET", f"/cam{folder}?kind=number")
            pages = json.loads(n)["pagenumber"]
            for p in range(1, pages + 1):
                _, lst = self.req("GET", f"/cam{folder}?page={p}")
                out += json.loads(lst)["path"]
        return out


class BridgeTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.env = Env(mock_range=False)

    @classmethod
    def tearDownClass(cls):
        cls.env.close()

    def test_status_ok(self):
        r, d = self.env.req("GET", "/bridge/status")
        s = json.loads(d)
        self.assertEqual(s["camera"]["state"], "ok")
        self.assertEqual(s["features"]["polling"], "/ccapi/ver100/event/polling")

    def test_app_is_served(self):
        r, d = self.env.req("GET", "/")
        self.assertEqual(r.status, 200)
        self.assertIn(b'data-mode="bridge"', d)
        r, d = self.env.req("GET", "/assets/js/app.js")
        self.assertEqual(r.status, 200)
        self.assertIn("javascript", r.getheader("Content-Type"))

    def test_original_is_byte_identical(self):
        for path in self.env.files():
            r, d = self.env.req("GET", f"/cam{path}")
            self.assertEqual(r.status, 200, path)
            self.assertEqual(hashlib.sha256(d).hexdigest(), hashlib.sha256(self.env.mock_get(path)).hexdigest(), path)

    def test_range_emulated_when_camera_ignores_it(self):
        path = next(p for p in self.env.files() if p.endswith(".CR3"))
        full = self.env.mock_get(path)
        r, d = self.env.req("GET", f"/cam{path}", {"Range": "bytes=1000-"})
        self.assertEqual(r.status, 206)
        self.assertEqual(r.getheader("Content-Range"), f"bytes 1000-{len(full) - 1}/{len(full)}")
        self.assertEqual(d, full[1000:])
        r, d = self.env.req("GET", f"/cam{path}", {"Range": "bytes=10-19"})
        self.assertEqual((r.status, d), (206, full[10:20]))
        r, _ = self.env.req("GET", f"/cam{path}", {"Range": f"bytes={len(full)}-"})
        self.assertEqual(r.status, 416)

    def test_delete_and_writes_are_blocked(self):
        path = self.env.files()[0]
        for method in ("DELETE", "PUT", "PATCH"):
            r, _ = self.env.req(method, f"/cam{path}")
            self.assertEqual(r.status, 405, method)
        r, _ = self.env.req("POST", f"/cam{path}")
        self.assertEqual(r.status, 405)
        log = json.loads(self.env.mock_get("/mock/log"))["log"]
        self.assertFalse([e for e in log if e.startswith(("DELETE", "PUT"))], log)
        self.assertFalse([e for e in log if e.startswith("POST") and "liveview" not in e and not e.startswith("POST /mock/")], log)

    def test_only_ccapi_paths(self):
        for p in ("/cam/mock/log", "/cam/ccapi/../mock/log", "/cam/etc/passwd"):
            r, _ = self.env.req("GET", p)
            self.assertEqual(r.status, 403, p)

    def test_thumbnail_cache(self):
        path = self.env.files()[1]
        self.env.req("GET", f"/cam{path}?kind=thumbnail")
        r, d = self.env.req("GET", f"/cam{path}?kind=thumbnail")
        self.assertEqual(r.getheader("X-Bridge-Cache"), "hit")
        self.assertEqual(d[:2], b"\xff\xd8")

    def test_zip_contains_unchanged_originals(self):
        paths = self.env.files()[:5]
        r, d = self.env.req("POST", "/bridge/zip", {"Content-Type": "application/json"}, json.dumps(paths))
        self.assertEqual(r.status, 200)
        with zipfile.ZipFile(io.BytesIO(d)) as zf:
            self.assertIsNone(zf.testzip())
            names = zf.namelist()
            self.assertEqual(len(names), 5)
            for p in paths:
                name = "/".join(p.split("/")[-2:])
                info = zf.getinfo(name)
                self.assertEqual(info.compress_type, zipfile.ZIP_STORED)
                self.assertEqual(zf.read(name), self.env.mock_get(p))

    def test_liveview_on_off(self):
        r, _ = self.env.req("GET", "/cam/ccapi/ver100/shooting/liveview/flip")
        self.assertEqual(r.status, 503)
        r, d = self.env.req("POST", "/bridge/liveview?size=medium")
        self.assertEqual(r.status, 200, d)
        r, d = self.env.req("GET", "/cam/ccapi/ver100/shooting/liveview/flip")
        self.assertEqual((r.status, d[:2]), (200, b"\xff\xd8"))
        r, _ = self.env.req("POST", "/bridge/liveview?size=huge")
        self.assertEqual(r.status, 400)
        self.env.req("POST", "/bridge/liveview?size=off")
        r, _ = self.env.req("GET", "/cam/ccapi/ver100/shooting/liveview/flip")
        self.assertEqual(r.status, 503)

    def test_event_polling_reports_new_shot(self):
        self.env.req("GET", "/cam/ccapi/ver100/event/polling?continue=off")
        added = json.loads(urllib.request.urlopen(urllib.request.Request(
            f"http://127.0.0.1:{self.env.mock_port}/mock/shoot?raw=1", method="POST")).read())["added"]
        _, d = self.env.req("GET", "/cam/ccapi/ver100/event/polling?continue=off")
        self.assertEqual(json.loads(d)["addedcontents"], added)

    def _break(self, path, after=200000):
        urllib.request.urlopen(urllib.request.Request(
            f"http://127.0.0.1:{self.env.mock_port}/mock/break?path={path}&after={after}", method="POST")).read()

    def test_broken_camera_stream_is_not_silently_completed(self):
        path = next(p for p in self.env.files() if p.endswith(".CR3"))
        self._break(path)
        conn = http.client.HTTPConnection("127.0.0.1", self.env.port, timeout=10)
        conn.request("GET", f"/cam{path}", headers={"X-KL-Transfer": "1"})
        r = conn.getresponse()
        with self.assertRaises(http.client.IncompleteRead):
            r.read()
        conn.close()
        # Fortsetzung ab Byte 200000 liefert genau den Rest
        full = self.env.mock_get(path)
        r, d = self.env.req("GET", f"/cam{path}", {"Range": "bytes=200000-", "X-KL-Transfer": "1"})
        self.assertEqual((r.status, d), (206, full[200000:]))

    def test_zip_with_broken_file_is_recognisably_broken(self):
        paths = self.env.files()[:3]
        self._break(paths[1], 1000)
        conn = http.client.HTTPConnection("127.0.0.1", self.env.port, timeout=20)
        conn.request("POST", "/bridge/zip", body=json.dumps(paths), headers={"Content-Type": "application/json"})
        data = conn.getresponse().read()
        conn.close()
        with self.assertRaises(zipfile.BadZipFile):
            zipfile.ZipFile(io.BytesIO(data))

    def test_discover_finds_camera(self):
        r, d = self.env.req("POST", "/bridge/discover")
        found = json.loads(d)["found"]
        self.assertIn(f"127.0.0.1:{self.env.mock_port}", [f["address"] for f in found])

    def test_set_camera_validation(self):
        r, _ = self.env.req("POST", "/bridge/camera?address=" + "a%20b")
        self.assertEqual(r.status, 400)
        r, d = self.env.req("POST", f"/bridge/camera?address=127.0.0.1:{self.env.mock_port}")
        self.assertEqual(json.loads(d)["camera"]["state"], "ok")


class RangePassthroughTests(unittest.TestCase):
    def test_camera_range_is_passed_through(self):
        env = Env(mock_range=True)
        try:
            path = env.files()[0]
            full = env.mock_get(path)
            r, d = env.req("GET", f"/cam{path}", {"Range": "bytes=500-"})
            self.assertEqual((r.status, d), (206, full[500:]))
            log = json.loads(env.mock_get("/mock/log"))["log"]
            self.assertTrue(any(path in e for e in log))
        finally:
            env.close()


class HttpsTests(unittest.TestCase):
    def test_https_with_local_ca(self):
        env = Env(mock_range=False, https=True)
        try:
            r, ca = env.req("GET", "/bridge/ca.crt")
            self.assertEqual(r.status, 200)
            self.assertIn(b"BEGIN CERTIFICATE", ca)
            ctx = ssl.create_default_context(cadata=ca.decode())
            ctx.check_hostname = True
            conn = http.client.HTTPSConnection("127.0.0.1", env.port + 1, context=ctx, timeout=10)
            conn.request("GET", "/bridge/status")
            st = json.loads(conn.getresponse().read())
            self.assertTrue(st["bridge"]["secure"])
            conn.close()
            # Server-Zertifikat erfüllt die iOS-Vorgaben (SAN, serverAuth, <= 825 Tage)
            crt = Path(env.tmp.name) / "tls" / "server.crt"
            txt = subprocess.run(["openssl", "x509", "-in", str(crt), "-noout", "-text"], capture_output=True, text=True).stdout
            self.assertIn("TLS Web Server Authentication", txt)
            self.assertIn("IP Address:127.0.0.1", txt)
            days = subprocess.run(["openssl", "x509", "-in", str(crt), "-noout", "-enddate"], capture_output=True, text=True).stdout
            self.assertTrue(days)
        finally:
            env.close()


if __name__ == "__main__":
    unittest.main(verbosity=2)
