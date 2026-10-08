#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Kamera-Brücke für Kamera-Link (Canon EOS M50 Mark II über CCAPI).

Warum es diese Brücke braucht: Die Kamera spricht CCAPI nur über unverschlüsseltes
HTTP und sendet keine CORS-Header. Safari darf sie deshalb weder von einer
HTTPS-Seite (gemischte Inhalte) noch von einer fremden HTTP-Seite (CORS) lesen.
Die Brücke liefert die Web-App selbst aus und leitet Kamera-Anfragen weiter –
dadurch ist alles "same origin" und funktioniert in Safari ohne Tricks.

Sicherheit: Weitergeleitet werden nur lesende GET-Anfragen auf /ccapi/…
Einzige schreibende Aktion ist das Ein-/Ausschalten des Livebilds.
DELETE (Löschen von Dateien) ist grundsätzlich gesperrt.

Nur Python-Standardbibliothek. Start:  python3 kamera_bruecke.py
"""
from __future__ import annotations

import argparse
import base64
import collections
import concurrent.futures
import http.client
import ipaddress
import json
import mimetypes
import os
import posixpath
import shutil
import socket
import ssl
import subprocess
import sys
import threading
import time
import zipfile
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from urllib.parse import parse_qs, quote, unquote, urlsplit

VERSION = "1.0.0"
DEFAULT_PORT = 8765
CAMERA_PORT = 8080

# Wird vom Build-Skript durch die eingebettete Web-App ersetzt (Pfad -> base64).
EMBEDDED_WEB: dict[str, str] = {}  # @@EMBED@@

READ_CHUNK = 64 * 1024


# --------------------------------------------------------------------------- #
# Konfiguration
# --------------------------------------------------------------------------- #
class Config:
    def __init__(self, data_dir: Path):
        self.data_dir = data_dir
        self.file = data_dir / "config.json"
        self.values: dict = {}
        try:
            self.values = json.loads(self.file.read_text("utf-8"))
        except (OSError, ValueError):
            self.values = {}

    def get(self, key, default=None):
        return self.values.get(key, default)

    def set(self, key, value):
        self.values[key] = value
        try:
            self.data_dir.mkdir(parents=True, exist_ok=True)
            self.file.write_text(json.dumps(self.values, indent=2), "utf-8")
        except OSError as exc:
            print(f"Hinweis: Einstellungen nicht gespeichert ({exc})")


def normalize_address(text: str) -> str:
    """'192.168.1.2' -> '192.168.1.2:8080'. Wirft ValueError bei Unsinn."""
    text = (text or "").strip()
    for prefix in ("http://", "https://"):
        if text.lower().startswith(prefix):
            text = text[len(prefix):]
    text = text.split("/")[0]
    if not text:
        raise ValueError("leer")
    host, _, port = text.rpartition(":") if text.count(":") == 1 else (text, "", "")
    if not host:
        host, port = text, ""
    port_num = int(port) if port else CAMERA_PORT
    if not (0 < port_num < 65536):
        raise ValueError("Port")
    allowed = set("abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789.-")
    if not host or any(c not in allowed for c in host):
        raise ValueError("Host")
    return f"{host}:{port_num}"


# --------------------------------------------------------------------------- #
# Kamera-Verbindung
# --------------------------------------------------------------------------- #
class CameraError(Exception):
    def __init__(self, code: str, message: str):
        super().__init__(message)
        self.code = code
        self.message = message


class Camera:
    """Hält die Kameraadresse, das CCAPI-Manifest und begrenzt parallele Zugriffe."""

    def __init__(self, address: str | None):
        self.address = address
        self.lock = threading.Lock()
        # Die Kamera ist langsam und verträgt wenige gleichzeitige Verbindungen.
        self.control_slots = threading.BoundedSemaphore(2)
        self.bulk_slots = threading.BoundedSemaphore(1)    # Warteschlange und ZIP
        self.stream_slots = threading.BoundedSemaphore(1)  # Video-Wiedergabe, volle Auflösung im Vollbild
        self.manifest: dict | None = None
        self.device: dict | None = None
        self.status_cache: tuple[float, dict] | None = None

    def set_address(self, address: str | None):
        with self.lock:
            self.address = address
            self.manifest = None
            self.device = None
            self.status_cache = None

    def connection(self, timeout: float) -> http.client.HTTPConnection:
        if not self.address:
            raise CameraError("no_camera", "Keine Kameraadresse eingestellt.")
        host, _, port = self.address.rpartition(":")
        return http.client.HTTPConnection(host, int(port), timeout=timeout)

    def request(self, method: str, path: str, *, body: bytes | None = None,
                headers: dict | None = None, timeout: float = 8.0):
        """Liefert (conn, response). Aufrufer muss conn.close() aufrufen."""
        conn = self.connection(timeout)
        try:
            conn.request(method, path, body=body, headers=headers or {})
            resp = conn.getresponse()
        except (OSError, http.client.HTTPException) as exc:
            conn.close()
            raise CameraError("camera_unreachable", f"Kamera nicht erreichbar: {exc}") from exc
        return conn, resp

    def get_json(self, path: str, timeout: float = 6.0) -> tuple[int, dict]:
        with self.control_slots:
            conn, resp = self.request("GET", path, timeout=timeout)
            try:
                raw = resp.read()
            finally:
                conn.close()
        try:
            data = json.loads(raw or b"{}")
        except ValueError:
            data = {}
        return resp.status, data if isinstance(data, dict) else {"value": data}

    def feature_path(self, feature: str) -> str | None:
        """Pfad eines Features aus dem Manifest (höchste angebotene Version)."""
        manifest = self.manifest or {}
        best = None
        for version in sorted(k for k in manifest if isinstance(manifest[k], list)):
            for entry in manifest[version]:
                path = str(entry.get("path", "")) if isinstance(entry, dict) else ""
                if path.rstrip("/").endswith(f"/{version}/{feature}"):
                    best = path
        return best

    def probe(self, force: bool = False) -> dict:
        now = time.time()
        if not force and self.status_cache and now - self.status_cache[0] < 2.0:
            return self.status_cache[1]
        result = {"address": self.address, "state": "no_address", "message": "", "versions": [], "device": None}
        if self.address:
            try:
                status, manifest = self.get_json("/ccapi", timeout=3.0)
                if status == 200 and manifest and all(isinstance(v, list) for v in manifest.values()):
                    self.manifest = manifest
                    result["state"] = "ok"
                    result["versions"] = sorted(manifest)
                    if self.device is None:
                        info_path = self.feature_path("deviceinformation")
                        if info_path:
                            st, dev = self.get_json(info_path, timeout=3.0)
                            if st == 200:
                                self.device = dev
                    result["device"] = self.device
                elif status == 404:
                    result["state"] = "not_ccapi"
                    result["message"] = ("Unter dieser Adresse antwortet etwas, aber ohne CCAPI. "
                                         "CCAPI ist nicht aktiviert oder im Kameramenü nicht verbunden.")
                else:
                    result["state"] = "not_ccapi"
                    result["message"] = f"Unerwartete Antwort der Kamera (HTTP {status})."
            except CameraError as exc:
                result["state"] = "unreachable"
                result["message"] = exc.message
        self.status_cache = (now, result)
        return result


# --------------------------------------------------------------------------- #
# Vorschau-Cache (Thumbnails, Info): schont die langsame Kamera
# --------------------------------------------------------------------------- #
class LruCache:
    def __init__(self, max_bytes: int):
        self.max_bytes = max_bytes
        self.items: collections.OrderedDict[str, tuple[str, bytes]] = collections.OrderedDict()
        self.size = 0
        self.lock = threading.Lock()

    def get(self, key):
        with self.lock:
            hit = self.items.get(key)
            if hit:
                self.items.move_to_end(key)
            return hit

    def put(self, key, ctype, data):
        with self.lock:
            if key in self.items:
                self.size -= len(self.items.pop(key)[1])
            self.items[key] = (ctype, data)
            self.size += len(data)
            while self.size > self.max_bytes and self.items:
                _, (_, old) = self.items.popitem(last=False)
                self.size -= len(old)

    def clear(self):
        with self.lock:
            self.items.clear()
            self.size = 0


# --------------------------------------------------------------------------- #
# Netzwerk-Hilfen
# --------------------------------------------------------------------------- #
def local_ipv4s() -> list[str]:
    ips: list[str] = []
    try:
        s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
        s.connect(("10.255.255.255", 1))
        ips.append(s.getsockname()[0])
        s.close()
    except OSError:
        pass
    try:
        for info in socket.getaddrinfo(socket.gethostname(), None, socket.AF_INET):
            ips.append(info[4][0])
    except OSError:
        pass
    out = []
    for ip in ips:
        if ip not in out and not ip.startswith("127."):
            out.append(ip)
    return out


def scan_for_cameras(port: int, extra: list[str], timeout: float = 0.4) -> list[dict]:
    """Sucht im eigenen /24-Netz nach Geräten, die unter /ccapi ein Manifest liefern."""
    candidates: list[str] = []
    for ip in local_ipv4s():
        net = ipaddress.ip_network(f"{ip}/24", strict=False)
        candidates += [f"{h}:{port}" for h in net.hosts() if str(h) != ip]
    candidates = list(dict.fromkeys(extra + candidates))

    def check(addr: str):
        host, _, p = addr.rpartition(":")
        try:
            with socket.create_connection((host, int(p)), timeout=timeout):
                pass
        except OSError:
            return None
        cam = Camera(addr)
        probe = cam.probe(force=True)
        if probe["state"] == "ok":
            dev = probe.get("device") or {}
            return {"address": addr, "name": dev.get("productname") or "CCAPI-Kamera"}
        return None

    found = []
    with concurrent.futures.ThreadPoolExecutor(max_workers=64) as pool:
        for res in pool.map(check, candidates):
            if res:
                found.append(res)
    return found


# --------------------------------------------------------------------------- #
# HTTPS (optional): lokale Zertifizierungsstelle für das iPhone
# --------------------------------------------------------------------------- #
def ensure_certificates(data_dir: Path, ips: list[str]) -> tuple[Path, Path, Path] | None:
    openssl = shutil.which("openssl")
    if not openssl:
        print("HTTPS nicht möglich: 'openssl' wurde nicht gefunden.")
        return None
    d = data_dir / "tls"
    d.mkdir(parents=True, exist_ok=True)
    ca_key, ca_crt = d / "ca.key", d / "ca.crt"
    srv_key, srv_crt, srv_ips = d / "server.key", d / "server.crt", d / "server-ips.json"
    host = socket.gethostname().split(".")[0]
    names = sorted(set(ips + ["127.0.0.1"]))

    def run(*args):
        subprocess.run([openssl, *args], check=True, capture_output=True)

    try:
        if not (ca_key.exists() and ca_crt.exists()):
            run("req", "-x509", "-newkey", "rsa:2048", "-nodes", "-keyout", str(ca_key), "-out", str(ca_crt),
                "-days", "3650", "-subj", f"/CN=Kamera-Bruecke CA ({host})",
                "-addext", "basicConstraints=critical,CA:TRUE",
                "-addext", "keyUsage=critical,keyCertSign,cRLSign")
        try:
            old = json.loads(srv_ips.read_text())
        except (OSError, ValueError):
            old = None
        if old != names or not srv_crt.exists():
            ext = d / "server.ext"
            alt = ",".join([f"IP:{ip}" for ip in names] + [f"DNS:{host}.local", "DNS:localhost"])
            ext.write_text("basicConstraints=CA:FALSE\nkeyUsage=critical,digitalSignature,keyEncipherment\n"
                           f"extendedKeyUsage=serverAuth\nsubjectAltName={alt}\n")
            csr = d / "server.csr"
            run("req", "-newkey", "rsa:2048", "-nodes", "-keyout", str(srv_key), "-out", str(csr),
                "-subj", f"/CN=Kamera-Bruecke {host}")
            # iOS akzeptiert Server-Zertifikate mit höchstens 825 Tagen Laufzeit.
            run("x509", "-req", "-in", str(csr), "-CA", str(ca_crt), "-CAkey", str(ca_key), "-CAcreateserial",
                "-out", str(srv_crt), "-days", "800", "-sha256", "-extfile", str(ext))
            srv_ips.write_text(json.dumps(names))
        for key in (ca_key, srv_key):
            try:
                os.chmod(key, 0o600)
            except OSError:
                pass
    except subprocess.CalledProcessError as exc:
        print("HTTPS-Zertifikat konnte nicht erstellt werden:", exc.stderr.decode(errors="replace")[:300])
        return None
    return ca_crt, srv_crt, srv_key


# --------------------------------------------------------------------------- #
# Web-App-Dateien
# --------------------------------------------------------------------------- #
class WebFiles:
    def __init__(self, web_dir: Path | None):
        self.web_dir = web_dir
        self.cache: dict[str, bytes] = {}

    def get(self, rel: str) -> bytes | None:
        rel = posixpath.normpath(rel).lstrip("/")
        if rel.startswith("..") or rel in ("", "."):
            return None
        if self.web_dir:
            p = (self.web_dir / rel).resolve()
            if self.web_dir.resolve() not in p.parents or not p.is_file():
                return None
            return p.read_bytes()
        if rel not in self.cache:
            if rel not in EMBEDDED_WEB:
                return None
            self.cache[rel] = base64.b64decode(EMBEDDED_WEB[rel])
        return self.cache[rel]


def mime_for(name: str) -> str:
    ext = name.rsplit(".", 1)[-1].lower()
    return {
        "html": "text/html; charset=utf-8", "js": "text/javascript; charset=utf-8",
        "css": "text/css; charset=utf-8", "svg": "image/svg+xml", "png": "image/png",
        "webmanifest": "application/manifest+json", "json": "application/json",
    }.get(ext) or mimetypes.guess_type(name)[0] or "application/octet-stream"


def parse_range(header: str | None) -> tuple[int, int | None] | None:
    if not header or not header.startswith("bytes=") or "," in header:
        return None
    a, _, b = header[6:].strip().partition("-")
    try:
        if a == "":
            return None  # Suffix-Bereiche braucht die App nicht
        return int(a), (int(b) if b else None)
    except ValueError:
        return None


# --------------------------------------------------------------------------- #
# HTTP-Server
# --------------------------------------------------------------------------- #
class BridgeState:
    def __init__(self, args, config: Config, camera: Camera, web: WebFiles):
        self.args = args
        self.config = config
        self.camera = camera
        self.web = web
        self.cache = LruCache(160 * 1024 * 1024)
        self.http_port = args.port
        self.https_port: int | None = None
        self.ca_crt: Path | None = None


class Handler(BaseHTTPRequestHandler):
    server_version = f"Kamera-Bruecke/{VERSION}"
    protocol_version = "HTTP/1.1"
    state: BridgeState  # wird gesetzt

    # ---- Hilfen -----------------------------------------------------------
    def log_message(self, fmt, *args):
        if self.state.args.verbose:
            sys.stderr.write("%s %s\n" % (self.address_string(), fmt % args))

    def send_json(self, obj, status=200):
        body = json.dumps(obj, ensure_ascii=False).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(body)))
        self.send_header("Cache-Control", "no-store")
        self.end_headers()
        if self.command != "HEAD":
            self.wfile.write(body)

    def send_bytes(self, data: bytes, ctype: str, cache: str = "no-cache", extra: dict | None = None):
        self.send_response(200)
        self.send_header("Content-Type", ctype)
        self.send_header("Content-Length", str(len(data)))
        self.send_header("Cache-Control", cache)
        self.send_header("X-Content-Type-Options", "nosniff")
        for k, v in (extra or {}).items():
            self.send_header(k, v)
        self.end_headers()
        if self.command != "HEAD":
            self.wfile.write(data)

    def error_json(self, status: int, code: str, message: str):
        self.send_json({"error": code, "message": message}, status)

    @property
    def is_secure(self) -> bool:
        return isinstance(self.connection, ssl.SSLSocket)

    # ---- Routing ----------------------------------------------------------
    def do_HEAD(self):
        self.do_GET()

    def do_GET(self):
        url = urlsplit(self.path)
        path = unquote(url.path)
        if path.startswith("/cam/"):
            return self.proxy_get(url)
        if path == "/bridge/status":
            return self.bridge_status()
        if path == "/bridge/ca.crt":
            return self.serve_ca()
        if path in ("/", "/index.html"):
            return self.serve_index()
        data = self.state.web.get(path)
        if data is None:
            return self.error_json(404, "not_found", "Nicht gefunden")
        cache = "no-cache" if path.endswith((".js", ".css", ".html", ".webmanifest")) else "max-age=86400"
        self.send_bytes(data, mime_for(path), cache)

    def do_POST(self):
        url = urlsplit(self.path)
        q = {k: v[0] for k, v in parse_qs(url.query).items()}
        if url.path == "/bridge/camera":
            return self.bridge_set_camera(q.get("address", ""))
        if url.path == "/bridge/discover":
            return self.bridge_discover(q.get("port"))
        if url.path == "/bridge/liveview":
            return self.bridge_liveview(q.get("size", "off"))
        if url.path == "/bridge/zip":
            return self.bridge_zip()
        self.error_json(405, "not_allowed", "Diese Aktion ist nicht erlaubt.")

    def do_DELETE(self):
        # Ausdrücklich gesperrt: Die App löscht niemals Dateien auf der Kamera.
        self.error_json(405, "not_allowed", "Löschen ist in der Kamera-Brücke gesperrt.")

    do_PUT = do_PATCH = do_DELETE

    # ---- Seiten -----------------------------------------------------------
    def serve_index(self):
        html = self.state.web.get("app.html")
        if html is None:
            return self.error_json(500, "missing_web", "Web-App-Dateien fehlen.")
        mode = "bridge"
        html = html.decode("utf-8").replace("{{MODE}}", mode).replace("{{VERSION}}", VERSION)
        self.send_bytes(html.encode("utf-8"), "text/html; charset=utf-8", "no-cache")

    def serve_ca(self):
        if not self.state.ca_crt or not self.state.ca_crt.exists():
            return self.error_json(404, "no_https", "HTTPS ist nicht eingeschaltet (Start mit --https).")
        self.send_bytes(self.state.ca_crt.read_bytes(), "application/x-x509-ca-cert", "no-store",
                        {"Content-Disposition": 'attachment; filename="Kamera-Bruecke.crt"'})

    def bridge_status(self):
        st = self.state
        cam = st.camera.probe()
        ips = local_ipv4s()
        payload = {
            "bridge": {
                "version": VERSION,
                "secure": self.is_secure,
                "httpUrls": [f"http://{ip}:{st.http_port}/" for ip in ips],
                "httpsUrls": [f"https://{ip}:{st.https_port}/" for ip in ips] if st.https_port else [],
                "caUrl": "/bridge/ca.crt" if st.https_port else None,
            },
            "camera": cam,
            "features": {
                "polling": st.camera.feature_path("event/polling"),
                "liveview": st.camera.feature_path("shooting/liveview"),
                "flip": st.camera.feature_path("shooting/liveview/flip"),
                "battery": st.camera.feature_path("devicestatus/battery"),
                "storage": st.camera.feature_path("devicestatus/storage"),
                "contents": st.camera.feature_path("contents"),
            } if cam["state"] == "ok" else {},
        }
        self.send_json(payload)

    def bridge_set_camera(self, address: str):
        try:
            addr = normalize_address(address)
        except ValueError:
            return self.error_json(400, "bad_address", "Ungültige Adresse. Beispiel: 192.168.1.2 oder 192.168.1.2:8080")
        self.state.camera.set_address(addr)
        self.state.cache.clear()
        self.state.config.set("camera", addr)
        self.send_json({"ok": True, "camera": self.state.camera.probe(force=True)})

    def bridge_discover(self, port: str | None):
        try:
            p = int(port) if port else CAMERA_PORT
        except ValueError:
            p = CAMERA_PORT
        found = scan_for_cameras(p, self.state.args.scan_extra or [])
        if len(found) == 1 and not self.state.camera.address:
            self.state.camera.set_address(found[0]["address"])
            self.state.config.set("camera", found[0]["address"])
        self.send_json({"found": found})

    def bridge_liveview(self, size: str):
        if size not in ("off", "small", "medium"):
            return self.error_json(400, "bad_size", "Ungültige Livebild-Grösse.")
        cam = self.state.camera
        if cam.probe()["state"] != "ok":
            return self.error_json(503, "camera_unreachable", "Kamera nicht verbunden.")
        path = cam.feature_path("shooting/liveview")
        if not path:
            return self.error_json(501, "unsupported", "Diese Kamera bietet über CCAPI kein Livebild an.")
        body = json.dumps({"liveviewsize": size, "cameradisplay": "on"}).encode()
        try:
            with cam.control_slots:
                conn, resp = cam.request("POST", path, body=body,
                                         headers={"Content-Type": "application/json"}, timeout=8)
                raw = resp.read()
                conn.close()
        except CameraError as exc:
            return self.error_json(502, exc.code, exc.message)
        try:
            msg = json.loads(raw or b"{}").get("message", "")
        except (ValueError, AttributeError):
            msg = ""
        if resp.status >= 400:
            return self.error_json(resp.status, "camera_refused", msg or f"Kamera lehnt ab (HTTP {resp.status}).")
        self.send_json({"ok": True})

    # ---- Kamera-Proxy (nur lesend) ----------------------------------------
    def proxy_get(self, url):
        cam_path = url.path[len("/cam"):]
        if not cam_path.startswith("/ccapi") or ".." in cam_path:
            return self.error_json(403, "forbidden", "Nur CCAPI-Pfade sind erlaubt.")
        query = parse_qs(url.query)
        kind = (query.get("kind") or [None])[0]
        is_file = "/contents/" in cam_path and cam_path.count("/") >= 6
        is_original = is_file and kind in (None, "main")
        target = cam_path + (f"?{url.query}" if url.query else "")
        cam = self.state.camera
        cacheable = is_file and kind in ("thumbnail", "info", "display") and self.command == "GET"
        key = f"{cam.address}{target}"
        if cacheable:
            hit = self.state.cache.get(key)
            if hit:
                return self.send_bytes(hit[1], hit[0], "private, max-age=86400", {"X-Bridge-Cache": "hit"})

        want = parse_range(self.headers.get("Range")) if is_original else None
        headers = {"Accept": "*/*"}
        if want:
            headers["Range"] = self.headers["Range"]
        if is_original:
            slots = cam.bulk_slots if self.headers.get("X-KL-Transfer") else cam.stream_slots
        else:
            slots = cam.control_slots
        timeout = 30.0 if is_original else 12.0
        with slots:
            try:
                conn, resp = cam.request(self.command if self.command == "HEAD" else "GET", target,
                                         headers=headers, timeout=timeout)
            except CameraError as exc:
                return self.error_json(502, exc.code, exc.message)
            try:
                self._relay(resp, want, cacheable, key, is_original)
            finally:
                conn.close()

    def _relay(self, resp, want, cacheable, key, is_original):
        ctype = resp.getheader("Content-Type") or "application/octet-stream"
        length = resp.getheader("Content-Length")
        total = int(length) if length and length.isdigit() else None

        if cacheable and resp.status == 200:
            data = resp.read()
            self.state.cache.put(key, ctype, data)
            return self.send_bytes(data, ctype, "private, max-age=86400")

        status = resp.status
        skip = 0
        out_len = total
        extra = {}
        if is_original:
            extra["Accept-Ranges"] = "bytes"
        if want and status == 200 and total is not None:
            # Kamera ignoriert Range: Brücke überspringt die Bytes selbst.
            start, end = want
            end = total - 1 if end is None or end >= total else end
            if start >= total:
                self.send_response(416)
                self.send_header("Content-Range", f"bytes */{total}")
                self.send_header("Content-Length", "0")
                self.end_headers()
                return
            skip = start
            out_len = end - start + 1
            status = 206
            extra["Content-Range"] = f"bytes {start}-{end}/{total}"
        elif status == 206 and resp.getheader("Content-Range"):
            extra["Content-Range"] = resp.getheader("Content-Range")

        self.send_response(status)
        self.send_header("Content-Type", ctype)
        if out_len is not None:
            self.send_header("Content-Length", str(out_len))
        else:
            self.close_connection = True
        self.send_header("Cache-Control", "no-store")
        for k, v in extra.items():
            self.send_header(k, v)
        self.end_headers()
        if self.command == "HEAD":
            return
        try:
            while skip > 0:
                chunk = resp.read(min(READ_CHUNK, skip))
                if not chunk:
                    return
                skip -= len(chunk)
            remaining = out_len
            while remaining is None or remaining > 0:
                chunk = resp.read(READ_CHUNK if remaining is None else min(READ_CHUNK, remaining))
                if not chunk:
                    break
                self.wfile.write(chunk)
                if remaining is not None:
                    remaining -= len(chunk)
            if remaining:
                # Kamera hat vorzeitig beendet: Verbindung schliessen, damit die App den
                # Abbruch erkennt (sonst wartet sie auf die fehlenden Bytes).
                self.close_connection = True
        except (BrokenPipeError, ConnectionResetError, socket.timeout, OSError, http.client.HTTPException):
            # Kamera oder iPhone hat die Verbindung verloren. Die Antwort bleibt unvollständig
            # (Content-Length nicht erreicht) – die App erkennt das und setzt per Range fort.
            self.close_connection = True

    # ---- ZIP mit Originalen (unkomprimiert, Bytes unverändert) -------------
    def bridge_zip(self):
        length = int(self.headers.get("Content-Length") or 0)
        raw = self.rfile.read(length) if 0 < length < 2_000_000 else b""
        ctype = self.headers.get("Content-Type", "")
        try:
            if ctype.startswith("application/x-www-form-urlencoded"):
                paths = json.loads(parse_qs(raw.decode())["paths"][0])
            else:
                paths = json.loads(raw or b"[]")
        except (ValueError, KeyError):
            return self.error_json(400, "bad_request", "Ungültige Dateiliste.")
        paths = [p for p in paths if isinstance(p, str) and p.startswith("/ccapi/") and "/contents/" in p
                 and ".." not in p]
        if not paths:
            return self.error_json(400, "bad_request", "Keine Dateien gewählt.")
        cam = self.state.camera
        stamp = time.strftime("%Y-%m-%d_%H-%M")
        self.send_response(200)
        self.send_header("Content-Type", "application/zip")
        self.send_header("Content-Disposition", f'attachment; filename="Kamera_{stamp}.zip"')
        self.send_header("Cache-Control", "no-store")
        self.send_header("Connection", "close")
        self.end_headers()
        self.close_connection = True

        class Sink:
            def __init__(self, w):
                self.w = w
                self.dead = False

            def write(self, b):
                if not self.dead:
                    self.w.write(b)
                return len(b)

            def flush(self):
                if not self.dead:
                    self.w.flush()

        sink = Sink(self.wfile)
        zf = zipfile.ZipFile(sink, "w", compression=zipfile.ZIP_STORED, allowZip64=True)
        try:
            for p in paths:
                name = "/".join(p.split("/")[-2:])
                with cam.bulk_slots:
                    for attempt in range(4):
                        conn, resp = cam.request("GET", quote(p), timeout=30)
                        if resp.status != 503 or attempt == 3:
                            break
                        resp.read()
                        conn.close()
                        time.sleep(1.0)  # Kamera beschäftigt
                    try:
                        if resp.status != 200:
                            raise OSError(f"{name}: Kamera antwortet HTTP {resp.status}")
                        expected = resp.getheader("Content-Length")
                        info = zipfile.ZipInfo(name, time.localtime()[:6])
                        info.compress_type = zipfile.ZIP_STORED
                        written = 0
                        with zf.open(info, "w", force_zip64=True) as dst:
                            while True:
                                chunk = resp.read(READ_CHUNK)
                                if not chunk:
                                    break
                                dst.write(chunk)
                                written += len(chunk)
                        if expected and expected.isdigit() and written != int(expected):
                            # Lieber ein erkennbar defektes ZIP als eine stillschweigend gekürzte Datei
                            raise OSError(f"{name}: nur {written} von {expected} Bytes")
                    finally:
                        conn.close()
            zf.close()
        except (CameraError, BrokenPipeError, ConnectionResetError, OSError, http.client.HTTPException) as exc:
            # Kein Inhaltsverzeichnis schreiben: Das ZIP ist dann erkennbar unvollständig.
            sink.dead = True
            try:
                zf.close()
            except Exception:
                pass
            if self.state.args.verbose:
                print("ZIP abgebrochen:", exc)


# --------------------------------------------------------------------------- #
# QR-Code fürs Terminal (Byte-Modus, Fehlerkorrektur M, Version 1–6)
# --------------------------------------------------------------------------- #
_ECC_M = [None, 10, 16, 26, 18, 24, 16]
_BLOCKS_M = [None, 1, 1, 1, 2, 2, 4]


def _gf_mul(x: int, y: int) -> int:
    z = 0
    for i in reversed(range(8)):
        z = (z << 1) ^ ((z >> 7) * 0x11D)
        z ^= ((y >> i) & 1) * x
    return z


def _rs_divisor(degree: int) -> list[int]:
    result = [0] * (degree - 1) + [1]
    root = 1
    for _ in range(degree):
        for j in range(degree):
            result[j] = _gf_mul(result[j], root)
            if j + 1 < degree:
                result[j] ^= result[j + 1]
        root = _gf_mul(root, 0x02)
    return result


def _rs_remainder(data: list[int], divisor: list[int]) -> list[int]:
    result = [0] * len(divisor)
    for b in data:
        factor = b ^ result.pop(0)
        result.append(0)
        for i, coef in enumerate(divisor):
            result[i] ^= _gf_mul(coef, factor)
    return result


def _raw_modules(ver: int) -> int:
    result = (16 * ver + 128) * ver + 64
    if ver >= 2:
        n = ver // 7 + 2
        result -= (25 * n - 10) * n - 55
    return result


def qr_matrix(text: str) -> list[list[bool]]:
    data = text.encode("utf-8")
    for ver in range(1, 7):
        cap = _raw_modules(ver) // 8 - _ECC_M[ver] * _BLOCKS_M[ver]
        if 4 + 8 + len(data) * 8 <= cap * 8:
            break
    else:
        raise ValueError("Text zu lang für QR")
    bits: list[int] = []

    def put(val, n):
        bits.extend((val >> i) & 1 for i in reversed(range(n)))

    put(0b0100, 4)
    put(len(data), 8)
    for b in data:
        put(b, 8)
    put(0, min(4, cap * 8 - len(bits)))
    put(0, (-len(bits)) % 8)
    pad = 0xEC
    while len(bits) < cap * 8:
        put(pad, 8)
        pad ^= 0xEC ^ 0x11
    words = [int("".join(map(str, bits[i:i + 8])), 2) for i in range(0, len(bits), 8)]

    nblocks, ecl = _BLOCKS_M[ver], _ECC_M[ver]
    raw = _raw_modules(ver) // 8
    nshort = nblocks - raw % nblocks
    short_len = raw // nblocks
    div = _rs_divisor(ecl)
    blocks, k = [], 0
    for i in range(nblocks):
        dat = words[k:k + short_len - ecl + (0 if i < nshort else 1)]
        k += len(dat)
        ecc = _rs_remainder(dat, div)
        if i < nshort:
            dat = dat + [0]
        blocks.append(dat + ecc)
    final = []
    for i in range(len(blocks[0])):
        for j, blk in enumerate(blocks):
            if i != short_len - ecl or j >= nshort:
                final.append(blk[i])

    size = ver * 4 + 17
    mod = [[False] * size for _ in range(size)]
    fn = [[False] * size for _ in range(size)]

    def setf(x, y, dark):
        mod[y][x] = dark
        fn[y][x] = True

    for i in range(size):
        setf(6, i, i % 2 == 0)
        setf(i, 6, i % 2 == 0)
    for cx, cy in ((3, 3), (size - 4, 3), (3, size - 4)):
        for dy in range(-4, 5):
            for dx in range(-4, 5):
                x, y = cx + dx, cy + dy
                if 0 <= x < size and 0 <= y < size:
                    setf(x, y, max(abs(dx), abs(dy)) not in (2, 4))
    if ver >= 2:
        n = ver // 7 + 2
        step = (ver * 8 + n * 3 + 5) // (n * 4 - 4) * 2
        pos = [6] + [size - 7 - i * step for i in reversed(range(n - 1))]
        for i, ax in enumerate(pos):
            for j, ay in enumerate(pos):
                if (i == 0 and j == 0) or (i == 0 and j == n - 1) or (i == n - 1 and j == 0):
                    continue
                for dy in range(-2, 3):
                    for dx in range(-2, 3):
                        setf(ax + dx, ay + dy, max(abs(dx), abs(dy)) != 1)

    def draw_format(mask):
        data_bits = (0 << 3) | mask  # M = 0b00
        rem = data_bits
        for _ in range(10):
            rem = (rem << 1) ^ ((rem >> 9) * 0x537)
        fbits = ((data_bits << 10) | rem) ^ 0x5412
        g = lambda i: (fbits >> i) & 1 == 1  # noqa: E731
        for i in range(6):
            setf(8, i, g(i))
        setf(8, 7, g(6))
        setf(8, 8, g(7))
        setf(7, 8, g(8))
        for i in range(9, 15):
            setf(14 - i, 8, g(i))
        for i in range(8):
            setf(size - 1 - i, 8, g(i))
        for i in range(8, 15):
            setf(8, size - 15 + i, g(i))
        setf(8, size - 8, True)

    draw_format(0)
    i = 0
    right = size - 1
    while right >= 1:
        if right == 6:
            right = 5
        for vert in range(size):
            for j in range(2):
                x = right - j
                upward = ((right + 1) & 2) == 0
                y = size - 1 - vert if upward else vert
                if not fn[y][x] and i < len(final) * 8:
                    mod[y][x] = (final[i >> 3] >> (7 - (i & 7))) & 1 == 1
                    i += 1
        right -= 2

    masks = [
        lambda x, y: (x + y) % 2 == 0, lambda x, y: y % 2 == 0, lambda x, y: x % 3 == 0,
        lambda x, y: (x + y) % 3 == 0, lambda x, y: (x // 3 + y // 2) % 2 == 0,
        lambda x, y: x * y % 2 + x * y % 3 == 0, lambda x, y: (x * y % 2 + x * y % 3) % 2 == 0,
        lambda x, y: ((x + y) % 2 + x * y % 3) % 2 == 0,
    ]

    def apply(m):
        for y in range(size):
            for x in range(size):
                if not fn[y][x] and masks[m](x, y):
                    mod[y][x] = not mod[y][x]

    def penalty():
        score = 0
        for grid in (mod, [list(c) for c in zip(*mod)]):
            for row in grid:
                run, prev = 0, None
                for v in row:
                    if v == prev:
                        run += 1
                    else:
                        if run >= 5:
                            score += run - 2
                        run, prev = 1, v
                if run >= 5:
                    score += run - 2
        for y in range(size - 1):
            for x in range(size - 1):
                if mod[y][x] == mod[y][x + 1] == mod[y + 1][x] == mod[y + 1][x + 1]:
                    score += 3
        dark = sum(v for row in mod for v in row)
        score += abs(dark * 20 - size * size * 10) // (size * size) * 10
        return score

    best, best_score = 0, None
    for m in range(8):
        apply(m)
        draw_format(m)
        s = penalty()
        if best_score is None or s < best_score:
            best, best_score = m, s
        apply(m)
    apply(best)
    draw_format(best)
    return mod


def qr_terminal(text: str) -> str:
    m = qr_matrix(text)
    n = len(m)
    q = 2
    get = lambda x, y: 0 <= x < n and 0 <= y < n and m[y][x]  # noqa: E731
    lines = []
    for y in range(-q, n + q, 2):
        line = ""
        for x in range(-q, n + q):
            top, bot = get(x, y), get(x, y + 1)
            line += "█" if top and bot else "▀" if top else "▄" if bot else " "
        lines.append("\033[30;47m" + line + "\033[0m")
    return "\n".join(lines)


# --------------------------------------------------------------------------- #
# Start
# --------------------------------------------------------------------------- #
def make_server(state: BridgeState, port: int, bind: str, tls: ssl.SSLContext | None):
    handler = type("BoundHandler", (Handler,), {"state": state})
    srv = ThreadingHTTPServer((bind, port), handler)
    srv.daemon_threads = True
    if tls:
        srv.socket = tls.wrap_socket(srv.socket, server_side=True, do_handshake_on_connect=False)
    return srv


def main(argv=None):
    ap = argparse.ArgumentParser(description="Kamera-Brücke für Kamera-Link (Canon CCAPI)")
    ap.add_argument("--camera", help="Kameraadresse, z. B. 192.168.1.2 oder 192.168.1.2:8080")
    ap.add_argument("--port", type=int, default=DEFAULT_PORT, help="HTTP-Port (Standard 8765)")
    ap.add_argument("--https", action="store_true",
                    help="zusätzlich HTTPS auf Port+1 (nötig für 'In Fotos sichern' über Teilen)")
    ap.add_argument("--bind", default="0.0.0.0", help="Netzwerkadresse (Standard: alle)")
    ap.add_argument("--data-dir", default=str(Path.home() / ".kamera-bruecke"))
    ap.add_argument("--web-dir", help="Web-App aus Ordner statt eingebettet (Entwicklung)")
    ap.add_argument("--scan-extra", action="append", help=argparse.SUPPRESS)
    ap.add_argument("--no-qr", action="store_true", help="keinen QR-Code anzeigen")
    ap.add_argument("--verbose", action="store_true")
    args = ap.parse_args(argv)

    if os.name == "nt":
        os.system("")  # ANSI-Farben in der Windows-Konsole aktivieren
        try:
            sys.stdout.reconfigure(encoding="utf-8")
        except (AttributeError, OSError):
            pass

    data_dir = Path(args.data_dir).expanduser()
    config = Config(data_dir)
    address = None
    if args.camera:
        try:
            address = normalize_address(args.camera)
            config.set("camera", address)
        except ValueError:
            print("Ungültige Kameraadresse:", args.camera)
            return 2
    else:
        address = config.get("camera")

    web = WebFiles(Path(args.web_dir).resolve() if args.web_dir else None)
    if not args.web_dir and not EMBEDDED_WEB:
        print("Diese Datei enthält keine Web-App. Bitte die fertige kamera-bruecke.py verwenden.")
        return 2
    state = BridgeState(args, config, Camera(address), web)

    servers = [make_server(state, args.port, args.bind, None)]
    ips = local_ipv4s()
    if args.https:
        certs = ensure_certificates(data_dir, ips)
        if certs:
            ca, crt, key = certs
            ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
            ctx.load_cert_chain(str(crt), str(key))
            state.https_port = args.port + 1
            state.ca_crt = ca
            servers.append(make_server(state, state.https_port, args.bind, ctx))

    for srv in servers:
        threading.Thread(target=srv.serve_forever, daemon=True).start()

    ip = ips[0] if ips else "127.0.0.1"
    url = f"http://{ip}:{args.port}/"
    print()
    print(f"  Kamera-Brücke {VERSION} läuft.")
    print()
    print(f"  Auf dem iPhone in Safari öffnen:  {url}")
    for other in ips[1:]:
        print(f"                           oder:  http://{other}:{args.port}/")
    if state.https_port:
        print(f"  Sicher (für 'In Fotos sichern'): https://{ip}:{state.https_port}/")
        print(f"  Zertifikat einmalig installieren:  {url}bridge/ca.crt")
    print()
    if not args.no_qr:
        try:
            print(qr_terminal(url))
            print("  QR-Code mit der iPhone-Kamera scannen.")
            print()
        except ValueError:
            pass
    probe = state.camera.probe(force=True)
    if probe["state"] == "ok":
        name = (probe.get("device") or {}).get("productname") or "Kamera"
        print(f"  Kamera: {name} unter {state.camera.address} – verbunden.")
    elif state.camera.address:
        print(f"  Kamera {state.camera.address}: noch nicht erreichbar. Die App sucht weiter.")
    else:
        print("  Noch keine Kamera eingestellt – in der App unter 'Verbindung' suchen oder eintragen.")
    print("  Beenden mit Ctrl+C.")
    print()
    try:
        while True:
            time.sleep(3600)
    except KeyboardInterrupt:
        print("\n  Brücke beendet.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
