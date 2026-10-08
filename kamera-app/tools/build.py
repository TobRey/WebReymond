#!/usr/bin/env python3
"""Baut die Auslieferung:
  dist/kamera/                     Ordner fürs Webhosting (beliebig umbenennbar)
  dist/kamera-link-hosting.zip     derselbe Ordner als ZIP für cPanel
  dist/kamera/download/…           Kamera-Brücke (eine Datei mit eingebetteter App) + Startdateien
"""
from __future__ import annotations

import base64
import json
import re
import shutil
import stat
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
WEB = ROOT / "web"
HOSTING = ROOT / "hosting"
BRIDGE = ROOT / "bridge"
IOS = ROOT / "ios"
DIST = ROOT / "dist"
OUT = DIST / "kamera"


def version() -> str:
    m = re.search(r'^VERSION = "([^"]+)"', (BRIDGE / "kamera_bruecke.py").read_text("utf-8"), re.M)
    return m.group(1)


def web_files() -> list[Path]:
    return sorted(p for p in WEB.rglob("*") if p.is_file() and not p.name.startswith("."))


def build_bridge(ver: str) -> str:
    src = (BRIDGE / "kamera_bruecke.py").read_text("utf-8")
    embed = {p.relative_to(WEB).as_posix(): base64.b64encode(p.read_bytes()).decode() for p in web_files()}
    marker = "EMBEDDED_WEB: dict[str, str] = {}  # @@EMBED@@"
    assert marker in src, "Einbettungs-Marker fehlt"
    body = "EMBEDDED_WEB: dict[str, str] = " + json.dumps(embed, indent=0, sort_keys=True)
    return src.replace(marker, body)


def zip_dir(zf: zipfile.ZipFile, folder: Path, prefix: str):
    for p in sorted(folder.rglob("*")):
        rel = f"{prefix}/{p.relative_to(folder).as_posix()}"
        if p.is_dir():
            continue
        info = zipfile.ZipInfo(rel, date_time=(2026, 1, 1, 0, 0, 0))
        mode = p.stat().st_mode
        info.external_attr = ((stat.S_IFREG | (0o755 if mode & stat.S_IXUSR else 0o644)) << 16)
        info.compress_type = zipfile.ZIP_DEFLATED
        zf.writestr(info, p.read_bytes())


def main():
    ver = version()
    if OUT.exists():
        shutil.rmtree(OUT)
    OUT.mkdir(parents=True)

    # Web-App
    for p in web_files():
        dst = OUT / p.relative_to(WEB)
        dst.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(p, dst)
    for p in HOSTING.iterdir():
        if p.is_file():
            shutil.copy2(p, OUT / p.name)
    assets = [p.relative_to(WEB).as_posix() for p in web_files() if p.name != "app.html"]
    sw = (OUT / "sw.js").read_text("utf-8")
    sw = sw.replace("@@VERSION@@", ver).replace("/*@@ASSETS@@*/", ", ".join(json.dumps(a) for a in assets))
    (OUT / "sw.js").write_text(sw, "utf-8")
    (OUT / "VERSION").write_text(ver + "\n", "utf-8")

    # Kamera-Brücke
    dl = OUT / "download"
    dl.mkdir()
    bridge_src = build_bridge(ver)
    (dl / "kamera-bruecke.py").write_text(bridge_src, "utf-8")
    (dl / "LIESMICH.txt").write_text((BRIDGE / "LIESMICH.txt").read_text("utf-8"), "utf-8")
    for system, ext in (("Mac", ".command"), ("Windows", ".bat")):
        stage = DIST / f"stage-{system}"
        if stage.exists():
            shutil.rmtree(stage)
        stage.mkdir()
        (stage / "kamera-bruecke.py").write_text(bridge_src, "utf-8")
        shutil.copy2(BRIDGE / "LIESMICH.txt", stage / "LIESMICH.txt")
        for s in (BRIDGE / "start").glob(f"*{ext}"):
            shutil.copy2(s, stage / s.name)
            if ext == ".command":
                (stage / s.name).chmod(0o755)
        with zipfile.ZipFile(dl / f"Kamera-Bruecke-{system}.zip", "w") as zf:
            zip_dir(zf, stage, "Kamera-Bruecke")
        shutil.rmtree(stage)
    if IOS.exists():
        with zipfile.ZipFile(dl / "Kamera-Link-iOS-Quellcode.zip", "w") as zf:
            zip_dir(zf, IOS, "Kamera-Link-iOS")
            for p in web_files():
                info = zipfile.ZipInfo(f"Kamera-Link-iOS/KameraLink/web/{p.relative_to(WEB).as_posix()}",
                                       date_time=(2026, 1, 1, 0, 0, 0))
                info.external_attr = (stat.S_IFREG | 0o644) << 16
                info.compress_type = zipfile.ZIP_DEFLATED
                zf.writestr(info, p.read_bytes())

    # Hosting-ZIP (ein Ordner "kamera", nach dem Entpacken frei umbenennbar)
    target = DIST / "kamera-link-hosting.zip"
    with zipfile.ZipFile(target, "w") as zf:
        zip_dir(zf, OUT, "kamera")
    # Eigenständige Brücke zusätzlich neben das ZIP legen (praktisch zum Testen)
    shutil.copy2(dl / "kamera-bruecke.py", DIST / "kamera-bruecke.py")
    print(f"Version {ver}")
    print(f"  {target.relative_to(ROOT)}  ({target.stat().st_size // 1024} KB)")
    print(f"  {(DIST / 'kamera-bruecke.py').relative_to(ROOT)}")


if __name__ == "__main__":
    main()
