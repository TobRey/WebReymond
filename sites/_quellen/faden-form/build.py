#!/usr/bin/env python3
"""Baut die statische Website «Faden & Form Atelier» nach sites/faden-form.ch/.

Aufruf:  python3 sites/_quellen/faden-form/build.py
Alle Texte stehen hier; Header, Footer und Illustrationen werden für jede Seite
gleich erzeugt. Wenn Fotos vorliegen, ersetzt man den Aufruf einer Illustration
durch ein <img>-Element.
"""
import html
import os

HERE = os.path.dirname(os.path.abspath(__file__))
OUT = os.path.normpath(os.path.join(HERE, "..", "..", "faden-form.ch"))
DOMAIN = "https://faden-form.ch"
LOGO = "/assets/img/logo.png"  # zugeschnitten aus 01m379p1cmm4jakz0kkk3g57m0.png
E = html.escape

# ------------------------------------------------------------------ Icons
ICONS = {
    "arrow": '<path d="M5 12h14M13 6l6 6-6 6"/>',
    "up": '<path d="M12 19V5M6 11l6-6 6 6"/>',
    "check": '<path d="M5 12.5l4.5 4.5L19 7.5"/>',
    "close": '<path d="M6 6l12 12M18 6 6 18"/>',
    "left": '<path d="M15 6l-6 6 6 6"/>',
    "right": '<path d="M9 6l6 6-6 6"/>',
    "lr": '<path d="M9 7l-5 5 5 5M15 7l5 5-5 5"/>',
    "pin": '<path d="M12 21s-7-6.2-7-11.5A7 7 0 0 1 19 9.5C19 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/>',
    "phone": '<path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2"/>',
    "mail": '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
    "clock": '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
    "needle": '<path d="M19.5 4.5 7 17l-2.5 2.5"/><ellipse cx="18" cy="6" rx="1.2" ry="2.6" transform="rotate(45 18 6)"/><path d="M17 7c-5 1-9 4-10 9 3-1 5 0 6 2"/>',
    "scissors": '<circle cx="6" cy="7" r="3"/><circle cx="6" cy="17" r="3"/><path d="M8.5 8.5 20 18M8.5 15.5 20 6"/>',
    "shirt": '<path d="M9 3 4 5.5 2.5 10l3 1.2V21h13V11.2l3-1.2L20 5.5 15 3a3 3 0 0 1-6 0z"/>',
    "spool": '<rect x="6" y="3" width="12" height="3" rx="1"/><rect x="6" y="18" width="12" height="3" rx="1"/><path d="M8 6v12M16 6v12M8 9h8M8 12h8M8 15h8"/>',
    "heart": '<path d="M12 20s-7.5-4.6-9-9.5A4.8 4.8 0 0 1 12 7a4.8 4.8 0 0 1 9 3.5c-1.5 4.9-9 9.5-9 9.5z"/>',
    "leaf": '<path d="M5 19c0-8 5-14 15-14 0 10-6 15-14 15"/><path d="M5 19c3-4 6-7 10-9"/>',
    "spark": '<path d="M12 3v4M12 17v4M3 12h4M17 12h4M6 6l2.5 2.5M15.5 15.5 18 18M6 18l2.5-2.5M15.5 8.5 18 6"/>',
    "users": '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7M18 14a6.5 6.5 0 0 1 3.5 6"/>',
    "instagram": '<rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.5" cy="6.5" r=".6" fill="currentColor"/>',
    "facebook": '<path d="M14 8h3V4h-3a4 4 0 0 0-4 4v3H7v4h3v6h4v-6h3l1-4h-4V8z"/>',
    "stitch": '<path d="M3 12h3M9 12h3M15 12h3M21 12h0" stroke-linecap="round"/><path d="M2 8c3 0 3 8 6 8s3-8 6-8 3 8 6 8"/>',
}


def icon(name, cls=""):
    c = f' class="{cls}"' if cls else ""
    return (f'<svg{c} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" '
            f'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{ICONS[name]}</svg>')


# ------------------------------------------------------------------ Illustrationen
_uid = [0]


def uid(p):
    _uid[0] += 1
    return f"{p}{_uid[0]}"


FABRICS = {
    # Grundfarbe, Musterfarbe, Musterart
    "denim": ("#3d5a80", "#5474a0", "twill"),
    "wolle": ("#a4553f", "#b8664f", "knit"),
    "jacke": ("#2c4a3e", "#3a5d4f", "quilt"),
    "hemd": ("#dfe8f1", "#a9bdd3", "stripe"),
    "hose": ("#4f555b", "#62686e", "pin"),
    "canvas": ("#b58c4c", "#c69d5c", "weave"),
    "leinen": ("#e9dcc4", "#d9c8aa", "weave"),
    "creme": ("#efe8d9", "#e3d9c4", "weave"),
}


def pattern(pid, kind, fg):
    if kind == "twill":
        return (f'<pattern id="{pid}" width="9" height="9" patternUnits="userSpaceOnUse" patternTransform="rotate(-35)">'
                f'<rect width="9" height="4" fill="{fg}" opacity=".55"/></pattern>')
    if kind == "knit":
        return (f'<pattern id="{pid}" width="14" height="12" patternUnits="userSpaceOnUse">'
                f'<path d="M0 0l7 10 7-10" fill="none" stroke="{fg}" stroke-width="3"/></pattern>')
    if kind == "quilt":
        return (f'<pattern id="{pid}" width="46" height="46" patternUnits="userSpaceOnUse" patternTransform="rotate(45)">'
                f'<path d="M0 0H46M0 0V46" stroke="{fg}" stroke-width="2" stroke-dasharray="5 4"/></pattern>')
    if kind == "stripe":
        return (f'<pattern id="{pid}" width="16" height="16" patternUnits="userSpaceOnUse">'
                f'<rect width="2" height="16" fill="{fg}"/></pattern>')
    if kind == "pin":
        return (f'<pattern id="{pid}" width="22" height="22" patternUnits="userSpaceOnUse">'
                f'<path d="M0 0V22" stroke="{fg}" stroke-width="1.2" stroke-dasharray="2 3"/></pattern>')
    return (f'<pattern id="{pid}" width="8" height="8" patternUnits="userSpaceOnUse">'
            f'<path d="M0 2H8M0 6H8" stroke="{fg}" stroke-width="1.4" opacity=".7"/>'
            f'<path d="M2 0V8M6 0V8" stroke="{fg}" stroke-width="1" opacity=".45"/></pattern>')


def fabric_bg(fab, w, h):
    base, fg, kind = FABRICS[fab]
    pid = uid("p")
    gid = uid("g")
    defs = (pattern(pid, kind, fg) +
            f'<radialGradient id="{gid}" cx="35%" cy="30%" r="85%">'
            f'<stop offset="0" stop-color="#fff" stop-opacity=".16"/>'
            f'<stop offset="1" stop-color="#000" stop-opacity=".22"/></radialGradient>')
    body = (f'<rect width="{w}" height="{h}" fill="{base}"/>'
            f'<rect width="{w}" height="{h}" fill="url(#{pid})"/>'
            f'<rect width="{w}" height="{h}" fill="url(#{gid})"/>')
    return defs, body


def svg(w, h, defs, body, label=None):
    a = f'role="img" aria-label="{E(label)}"' if label else 'aria-hidden="true"'
    return (f'<svg viewBox="0 0 {w} {h}" preserveAspectRatio="xMidYMid slice" {a} '
            f'xmlns="http://www.w3.org/2000/svg"><defs>{defs}</defs>{body}</svg>')


def seam(w, h, color, inset=22):
    return (f'<rect x="{inset}" y="{inset}" width="{w - 2 * inset}" height="{h - 2 * inset}" rx="6" fill="none" '
            f'stroke="{color}" stroke-width="2.4" stroke-dasharray="10 7" opacity=".55"/>')


def repair(kind, state, fab, w=400, h=500, label=None):
    """Detailansicht einer Reparatur: state = 'vorher' | 'nachher'."""
    defs, body = fabric_bg(fab, w, h)
    cx, cy = w / 2, h / 2
    thread_light = "#f7f3ea"
    terra = "#c46a4f"
    if kind == "flicken":
        if state == "vorher":
            body += (f'<path d="M{cx-70} {cy-30} l22 -18 l18 14 l26 -30 l20 22 l30 -8 l-6 30 l24 26 l-30 10 '
                     f'l4 32 l-32 -10 l-22 22 l-14 -28 l-34 4 l10 -30 l-26 -18 z" fill="#1d2b3d"/>'
                     f'<path d="M{cx-70} {cy-30} l22 -18 l18 14 l26 -30 l20 22 l30 -8 l-6 30 l24 26 l-30 10 '
                     f'l4 32 l-32 -10 l-22 22 l-14 -28 l-34 4 l10 -30 l-26 -18 z" fill="none" stroke="#e8edf3" '
                     f'stroke-width="3" stroke-dasharray="2 5" stroke-linecap="round"/>')
            for i in range(9):
                x = cx - 60 + i * 15
                body += f'<path d="M{x} {cy + 44 - (i % 3) * 6} q4 16 -2 30" stroke="#e8edf3" stroke-width="2" fill="none" opacity=".8"/>'
        else:
            pid = uid("p")
            defs += pattern(pid, "twill", "#8aa6c8")
            body += (f'<rect x="{cx-105}" y="{cy-95}" width="210" height="190" rx="18" fill="#6f8db3"/>'
                     f'<rect x="{cx-105}" y="{cy-95}" width="210" height="190" rx="18" fill="url(#{pid})"/>'
                     f'<rect x="{cx-92}" y="{cy-82}" width="184" height="164" rx="12" fill="none" stroke="{terra}" '
                     f'stroke-width="3.2" stroke-dasharray="11 7" stroke-linecap="round"/>')
            for r in range(5):
                y = cy - 52 + r * 26
                body += (f'<path d="M{cx-66} {y}H{cx+66}" stroke="{thread_light}" stroke-width="2.6" '
                         f'stroke-dasharray="8 8" stroke-linecap="round" opacity=".9"/>')
    elif kind == "stopfen":
        if state == "vorher":
            body += (f'<ellipse cx="{cx}" cy="{cy}" rx="62" ry="54" fill="#3a1d16"/>'
                     f'<path d="M{cx-62} {cy} q-10 -30 12 -46 M{cx+50} {cy-30} q30 6 20 36 M{cx-20} {cy+54} q10 22 34 10 '
                     f'M{cx+40} {cy+40} q24 18 8 40 M{cx-54} {cy+26} q-26 10 -18 34" stroke="#c9735b" stroke-width="5" '
                     f'fill="none" stroke-linecap="round"/>')
        else:
            body += f'<ellipse cx="{cx}" cy="{cy}" rx="88" ry="78" fill="#a4553f" opacity=".5"/>'
            for i in range(-6, 7):
                x = cx + i * 12
                half = (1 - (i / 7) ** 2) ** .5 * 76
                body += (f'<path d="M{x} {cy-half}V{cy+half}" stroke="#f1e6cf" stroke-width="7" '
                         f'stroke-linecap="round"/>')
            for j in range(-5, 6):
                y = cy + j * 12
                half = (1 - (j / 6.3) ** 2) ** .5 * 84
                body += (f'<path d="M{cx-half} {y}H{cx+half}" stroke="#234638" stroke-width="6.5" '
                         f'stroke-dasharray="12 12" stroke-dashoffset="{(j % 2) * 12}" stroke-linecap="butt"/>')
    elif kind == "reissverschluss":
        body += f'<rect x="{cx-16}" y="0" width="32" height="{h}" fill="#1c2f28" opacity=".55"/>'
        teeth = ""
        for i in range(0, int(h / 14)):
            y = i * 14 + 4
            split = state == "vorher" and y > h * 0.46
            off = min(46, (y - h * 0.46) * 0.28) if split else 0
            teeth += (f'<rect x="{cx - 14 - off}" y="{y}" width="14" height="8" rx="2" fill="#d8c9a6"/>'
                      f'<rect x="{cx - off + (0 if split else 0)}" y="{y + 7}" width="14" height="8" rx="2" fill="#d8c9a6"/>'
                      if not split else
                      f'<rect x="{cx - 14 - off}" y="{y}" width="14" height="8" rx="2" fill="#d8c9a6"/>'
                      f'<rect x="{cx + off}" y="{y + 7}" width="14" height="8" rx="2" fill="#d8c9a6"/>')
        body += teeth
        sy = h * 0.18 if state == "vorher" else h * 0.12
        pull = "#9a9a8e" if state == "vorher" else terra
        body += (f'<rect x="{cx-20}" y="{sy}" width="40" height="46" rx="8" fill="#e7e1d2" stroke="#6b6b62" stroke-width="2"/>'
                 f'<rect x="{cx-9}" y="{sy+38}" width="18" height="{70 if state == "nachher" else 26}" rx="7" fill="{pull}"/>')
        if state == "vorher":
            body += (f'<path d="M{cx-9} {sy+62} l6 10 -8 6" stroke="#6b6b62" stroke-width="2" fill="none"/>')
        else:
            body += (f'<path d="M{cx-34} 0V{h}M{cx+34} 0V{h}" stroke="{thread_light}" stroke-width="2.4" '
                     f'stroke-dasharray="9 6" opacity=".8"/>')
    elif kind == "kragen":
        body += (f'<path d="M0 {h*0.34} Q{cx} {h*0.46} {w} {h*0.34} V0 H0 Z" fill="#ecf1f7"/>'
                 f'<path d="M0 {h*0.34} Q{cx} {h*0.46} {w} {h*0.34}" fill="none" stroke="#9fb2c8" stroke-width="2"/>')
        if state == "vorher":
            for i in range(26):
                x = 8 + i * 15.5
                y = h * 0.34 + (1 - ((x - cx) / cx) ** 2) * h * 0.06
                body += (f'<path d="M{x} {y} q{3 + i % 3} {10 + (i % 4) * 5} {-2 + i % 5} {18 + (i % 3) * 6}" '
                         f'stroke="#7f93ab" stroke-width="1.8" fill="none"/>')
            body += (f'<path d="M0 {h*0.34+8} Q{cx} {h*0.46+8} {w} {h*0.34+8}" fill="none" stroke="#b9c7d7" '
                     f'stroke-width="10" opacity=".7"/>')
        else:
            for off in (-14, -24):
                body += (f'<path d="M0 {h*0.34+off} Q{cx} {h*0.46+off} {w} {h*0.34+off}" fill="none" '
                         f'stroke="#234638" stroke-width="2" stroke-dasharray="6 5"/>')
        body += (f'<circle cx="{cx}" cy="{h*0.62}" r="13" fill="#f4f1ea" stroke="#9fb2c8" stroke-width="2"/>'
                 f'<circle cx="{cx-4}" cy="{h*0.62-4}" r="2" fill="#9fb2c8"/><circle cx="{cx+4}" cy="{h*0.62+4}" r="2" fill="#9fb2c8"/>'
                 f'<path d="M{cx} 0V{h}" stroke="#b9c7d7" stroke-width="2" opacity=".6"/>')
    elif kind == "saum":
        if state == "vorher":
            d = f"M0 {h*0.8}"
            for i in range(21):
                d += f" L{i*20} {h*0.8 + (18 if i % 2 else 4) + (i % 3) * 6}"
            body += f'<path d="{d} L{w} {h} L0 {h} Z" fill="#e8dfcd"/>'
            for i in range(30):
                x = 6 + i * 13.3
                body += (f'<path d="M{x} {h*0.82 + (i % 4) * 5} q{-3 + i % 6} 14 {1 + i % 3} {24 + (i % 5) * 4}" '
                         f'stroke="#7d8389" stroke-width="1.6" fill="none"/>')
            body += f'<path d="M0 {h*0.56}H{w}" stroke="#7d8389" stroke-width="2" stroke-dasharray="3 9" opacity=".7"/>'
        else:
            body += (f'<rect y="{h*0.72}" width="{w}" height="{h*0.28}" fill="#e8dfcd"/>'
                     f'<rect y="{h*0.64}" width="{w}" height="{h*0.08}" fill="#3f454b"/>'
                     f'<path d="M0 {h*0.64}H{w}" stroke="#2f3438" stroke-width="3"/>'
                     f'<path d="M0 {h*0.6}H{w}" stroke="{terra}" stroke-width="2.6" stroke-dasharray="9 6"/>')
        body += f'<path d="M{cx} 0V{h*0.6}" stroke="#6f757b" stroke-width="2.5" opacity=".6"/>'
    elif kind == "traeger":
        body += (f'<rect x="0" y="{h*0.5}" width="{w}" height="{h*0.5}" fill="#9c7640"/>'
                 f'<path d="M0 {h*0.5}H{w}" stroke="#7d5c2e" stroke-width="4"/>')
        sx = cx - 50
        if state == "vorher":
            body += (f'<path d="M{sx} -10 L{sx+100} -10 L{sx+100} {h*0.44} L{sx+70} {h*0.47} L{sx+40} {h*0.43} L{sx} {h*0.46} Z" '
                     f'fill="#2d2d2a" transform="rotate(-9 {cx} {h*0.4})"/>')
            for i in range(8):
                x = sx + 6 + i * 12
                body += f'<path d="M{x} {h*0.52} q-4 10 3 18" stroke="#e5d6b5" stroke-width="2" fill="none"/>'
            body += f'<path d="M{sx} {h*0.53}H{sx+100}" stroke="#e5d6b5" stroke-width="2" stroke-dasharray="4 10" opacity=".6"/>'
        else:
            body += (f'<rect x="{sx}" y="-10" width="100" height="{h*0.5+70}" fill="#2d2d2a"/>'
                     f'<rect x="{sx+10}" y="{h*0.5}" width="80" height="60" fill="none" stroke="{terra}" stroke-width="3.2"/>'
                     f'<path d="M{sx+10} {h*0.5}l80 60M{sx+90} {h*0.5}l-80 60" stroke="{terra}" stroke-width="3.2"/>'
                     f'<path d="M{sx+8} -10V{h*0.48}M{sx+92} -10V{h*0.48}" stroke="#e5d6b5" stroke-width="2" stroke-dasharray="7 6"/>')
    body += seam(w, h, "#f7f3ea" if fab not in ("hemd", "leinen", "creme") else "#234638")
    return svg(w, h, defs, body, label)


def hero_art():
    """Startseite: Stoffmuster in Schichten, Nadel mit Faden."""
    w, h = 500, 540
    d1, b1 = fabric_bg("creme", w, h)
    layers = []
    for fab, x, y, rw, rh, rot, speed in [
        ("denim", 40, 70, 250, 300, -8, 0.06),
        ("wolle", 210, 40, 240, 260, 7, 0.14),
        ("jacke", 150, 250, 280, 240, -3, 0.22),
    ]:
        dd, bb = fabric_bg(fab, rw, rh)
        layers.append((dd, bb, x, y, rw, rh, rot, speed))
    out = [f'<div class="art" style="position:absolute;inset:0;border-radius:32px">{svg(w, h, d1, b1)}</div>']
    for dd, bb, x, y, rw, rh, rot, speed in layers:
        inner = svg(rw, rh, dd, bb + seam(rw, rh, "#f7f3ea", 14))
        out.append(
            f'<div data-speed="{speed}" style="position:absolute;left:{x / w * 100:.1f}%;top:{y / h * 100:.1f}%;'
            f'width:{rw / w * 100:.1f}%;aspect-ratio:{rw}/{rh};">'
            f'<div style="width:100%;height:100%;border-radius:18px;overflow:hidden;transform:rotate({rot}deg);'
            f'box-shadow:0 24px 40px -22px rgba(27,58,46,.55)">{inner}</div></div>')
    needle = (
        '<svg viewBox="0 0 500 540" style="position:absolute;inset:0;width:100%;height:100%;overflow:visible" aria-hidden="true">'
        '<path data-thread d="M70 470 C 40 380, 170 360, 200 300 S 170 170, 260 150 S 420 210, 390 300 S 250 360, 330 250 L 372 196" '
        'fill="none" stroke="#b85f45" stroke-width="5" stroke-linecap="round"/>'
        '<g data-drift="0,-30,6"><g transform="rotate(38 380 180)">'
        '<path d="M380 40 L388 60 L386 320 L380 360 L374 320 L372 60 Z" fill="#1b3a2e"/>'
        '<ellipse cx="380" cy="70" rx="3.2" ry="14" fill="#efe8d9"/></g></g></svg>')
    out.append(f'<div data-speed="0.04" style="position:absolute;inset:0">{needle}</div>')
    return "".join(out)


def scene(kind, label, fab="creme"):
    """Illustrationen für Bild-Text-Blöcke (bis Fotos vorliegen)."""
    w, h = 800, 1000
    defs, body = fabric_bg(fab, w, h)
    if kind == "spulen":
        cols = [("#b85f45", "#9c4c35"), ("#234638", "#18332a"), ("#d7b46a", "#b8964f"), ("#3d5a80", "#2c4466"), ("#efe8d9", "#d9ceb8")]
        for i, (c, c2) in enumerate(cols):
            x = 110 + (i % 3) * 210 + (i // 3) * 105
            y = 250 + (i // 3) * 330
            body += (f'<g data-drift="0,{-20 - i * 8},0"><rect x="{x-8}" y="{y-12}" width="136" height="26" rx="8" fill="#c7a878"/>'
                     f'<rect x="{x}" y="{y+10}" width="120" height="200" fill="{c}"/>')
            for k in range(12):
                body += f'<path d="M{x} {y+20+k*16}H{x+120}" stroke="{c2}" stroke-width="3" opacity=".6"/>'
            body += (f'<rect x="{x-8}" y="{y+206}" width="136" height="26" rx="8" fill="#c7a878"/></g>')
        body += ('<path d="M230 480 C 300 700, 520 620, 600 820 S 700 960, 780 900" stroke="#b85f45" stroke-width="5" '
                 'fill="none" stroke-linecap="round"/>')
    elif kind == "maschine":
        body += ('<g fill="#234638">'
                 '<path d="M150 360 H560 a70 70 0 0 1 70 70 V640 H560 V470 H260 V560 H150 Z"/>'
                 '<rect x="110" y="640" width="580" height="70" rx="16"/>'
                 '<rect x="220" y="560" width="30" height="80"/>'
                 '</g>'
                 '<circle cx="600" cy="420" r="44" fill="#b85f45"/><circle cx="600" cy="420" r="16" fill="#efe8d9"/>'
                 '<rect x="232" y="620" width="6" height="40" fill="#b8b8b0"/>'
                 '<rect x="300" y="300" width="36" height="60" rx="6" fill="#d7b46a"/>'
                 '<path d="M318 300 C 318 240, 420 230, 460 360" stroke="#b85f45" stroke-width="4" fill="none"/>'
                 '<rect x="120" y="710" width="560" height="14" rx="6" fill="#18332a"/>'
                 '<path d="M60 790 H740" stroke="#b85f45" stroke-width="4" stroke-dasharray="16 11"/>')
        body += ('<g data-drift="30,0,0"><rect x="80" y="760" width="640" height="150" rx="10" fill="#3d5a80" opacity=".9"/>'
                 '<path d="M80 800 H720" stroke="#efe8d9" stroke-width="3" stroke-dasharray="12 8"/></g>')
    elif kind == "werkzeug":
        body += ('<g data-drift="0,-40,-4" transform="rotate(-24 400 420)">'
                 '<circle cx="250" cy="300" r="70" fill="none" stroke="#234638" stroke-width="22"/>'
                 '<circle cx="250" cy="520" r="70" fill="none" stroke="#234638" stroke-width="22"/>'
                 '<path d="M310 340 L700 470 L690 490 L300 380 Z" fill="#9aa3a0"/>'
                 '<path d="M310 480 L700 350 L690 330 L300 440 Z" fill="#b5bdba"/>'
                 '<circle cx="330" cy="410" r="12" fill="#234638"/></g>'
                 '<g data-drift="0,30,3"><path d="M40 760 C 200 620, 420 900, 780 700" stroke="#d7b46a" stroke-width="54" fill="none"/>'
                 '<path d="M40 760 C 200 620, 420 900, 780 700" stroke="#8f6f2d" stroke-width="54" fill="none" '
                 'stroke-dasharray="2 22" opacity=".8"/></g>')
        for i in range(6):
            x, y = 520 + (i % 3) * 60, 180 + (i // 3) * 70
            body += (f'<g data-drift="0,{-10 - i * 6},0"><path d="M{x} {y} l40 90" stroke="#8a8f8d" stroke-width="4"/>'
                     f'<circle cx="{x}" cy="{y}" r="14" fill="{["#b85f45", "#234638", "#d7b46a"][i % 3]}"/></g>')
    elif kind == "stapel":
        colors = ["#3d5a80", "#a4553f", "#234638", "#d7b46a", "#dfe8f1", "#4f555b"]
        for i, c in enumerate(colors):
            y = 780 - i * 95
            wdt = 520 - (i % 2) * 40
            x = 400 - wdt / 2 + (i % 3 - 1) * 14
            body += (f'<g data-drift="{(i % 2 * 2 - 1) * 14},0,0"><rect x="{x}" y="{y}" width="{wdt}" height="88" rx="14" fill="{c}"/>'
                     f'<path d="M{x+18} {y+14}H{x+wdt-18}" stroke="{"#234638" if c in ("#dfe8f1", "#d7b46a") else "#f7f3ea"}" '
                     f'stroke-width="2.4" stroke-dasharray="9 6" opacity=".7"/></g>')
        body += ('<path d="M150 180 C 300 60, 520 260, 660 130" stroke="#b85f45" stroke-width="5" fill="none" stroke-linecap="round"/>')
    elif kind == "haende":
        body += ('<rect x="0" y="560" width="800" height="440" fill="#c9a87a"/>'
                 '<path d="M0 560H800" stroke="#a88958" stroke-width="4"/>'
                 '<g data-drift="0,-24,-2"><rect x="170" y="600" width="460" height="300" rx="16" fill="#3d5a80" transform="rotate(-5 400 750)"/>'
                 '<rect x="330" y="680" width="150" height="130" rx="14" fill="#b85f45" transform="rotate(-5 400 750)"/>'
                 '<rect x="340" y="690" width="130" height="110" rx="10" fill="none" stroke="#f7f3ea" stroke-width="3" '
                 'stroke-dasharray="9 6" transform="rotate(-5 400 750)"/></g>'
                 '<g fill="#e3b79a"><path d="M40 520 C 140 470, 250 520, 330 640 L300 690 C 220 620, 140 610, 40 640 Z"/>'
                 '<path d="M760 500 C 660 470, 560 540, 500 640 L540 680 C 600 610, 670 600, 760 620 Z"/></g>'
                 '<path d="M520 640 L620 420" stroke="#1b3a2e" stroke-width="7" stroke-linecap="round"/>'
                 '<path d="M620 420 C 700 330, 560 250, 470 330 S 420 560, 440 690" stroke="#b85f45" stroke-width="4" fill="none"/>')
    body += seam(w, h, "#234638" if fab == "creme" else "#f7f3ea", 30)
    return svg(w, h, defs, body, label)


def map_bg():
    w, h = 1200, 520
    body = '<rect width="1200" height="520" fill="#e9e1cf"/>'
    for x in range(-100, 1300, 140):
        body += f'<path d="M{x} 0 L{x+120} 520" stroke="#f7f3ea" stroke-width="14"/>'
    for y in range(40, 520, 110):
        body += f'<path d="M0 {y} L1200 {y-40}" stroke="#f7f3ea" stroke-width="18"/>'
    body += ('<path d="M0 380 C 300 330, 600 440, 1200 300" stroke="#b9cdbf" stroke-width="40" fill="none"/>'
             '<circle cx="600" cy="250" r="16" fill="#b85f45"/><circle cx="600" cy="250" r="34" fill="#b85f45" opacity=".2"/>')
    return svg(w, h, "", body)


# ------------------------------------------------------------------ Bausteine
def btn(label, href, style="", arrow=True):
    cls = "btn" + (f" btn--{style}" if style else "")
    a = icon("arrow") if arrow else ""
    return f'<a class="{cls}" href="{href}">{E(label)}{a}</a>'


def kicker(t):
    return f'<p class="kicker">{E(t)}</p>'


def marquee(items, variant=""):
    group = "".join(f'<span class="marquee__item">{E(i)}{icon("needle")}</span>' for i in items)
    cls = "marquee" + (f" marquee--{variant}" if variant else "")
    return (f'<div class="{cls}" aria-hidden="true"><div class="marquee__track">'
            f'<div class="marquee__group">{group}</div><div class="marquee__group">{group}</div></div></div>'
            f'<p class="sr">{E(", ".join(items))}</p>')


def compare(kind, fab, title_before, title_after, label):
    b = repair(kind, "vorher", fab, 400, 300)
    a = repair(kind, "nachher", fab, 400, 300)
    return (f'<div class="compare" style="--pos:50%">'
            f'<div class="compare__layer" role="img" aria-label="{E(title_before)}">{b}</div>'
            f'<div class="compare__layer compare__after" role="img" aria-label="{E(title_after)}">{a}</div>'
            f'<span class="compare__label compare__label--before">Vorher</span>'
            f'<span class="compare__label compare__label--after">Nachher</span>'
            f'<div class="compare__handle"><span class="compare__knob">{icon("lr")}</span></div>'
            f'<input type="range" min="0" max="100" value="50" aria-label="{E(label)}"></div>')


def steps(items, dark=False):
    out = f'<ol class="steps" style="--n:{len(items)};list-style:none;padding:0;margin:0">'
    for i, (t, p) in enumerate(items, 1):
        out += f'<li class="step"><div class="step__num" aria-hidden="true">{i}</div><h3>{E(t)}</h3><p>{E(p)}</p></li>'
    return out + "</ol>"


def cta(title, text, buttons):
    deco = ('<svg class="cta__deco" viewBox="0 0 200 200" aria-hidden="true"><path d="M20 180 C 0 100, 120 120, 110 60 '
            'S 180 0, 190 90" fill="none" stroke="currentColor" stroke-width="3" stroke-dasharray="8 6"/>'
            '<circle cx="100" cy="100" r="60" fill="none" stroke="currentColor" stroke-width="2"/></svg>')
    return (f'<section class="sec sec--tight"><div class="wrap"><div class="cta" data-drift="0,0,0">{deco}'
            f'<div><h2>{E(title)}</h2><p>{E(text)}</p></div>'
            f'<div class="btns">{"".join(buttons)}</div></div></div></section>')


def page_hero(k, title, lead, extra="", art=""):
    right = f'<div>{art}</div>' if art else ""
    return (f'<section class="page-hero"><div class="wrap page-hero__grid"><div>{kicker(k)}'
            f'<h1>{title}</h1><p class="lead">{E(lead)}</p>{extra}</div>{right}</div>'
            f'<div class="page-hero__stitch" aria-hidden="true"></div></section>')


def split(media, k, title, body_html, rev=False, note=None, surface=False, anchor=None, extra=""):
    nt = ""
    if note:
        nt = f'<div class="float-note" data-drift="0,-30,0"><strong>{E(note[0])}</strong>{E(note[1])}</div>'
    a = f' id="{anchor}"' if anchor else ""
    s = " sec--surface" if surface else ""
    r = " split--rev" if rev else ""
    return (f'<section class="sec{s}"{a}><div class="wrap split{r}">'
            f'<div class="split__media"><div class="art art--tall parallax-box"><div class="px" data-speed="0.08">{media}</div></div>{nt}</div>'
            f'<div class="split__text">{kicker(k)}<h2>{title}</h2>{body_html}{extra}</div></div></section>')


# ------------------------------------------------------------------ Layout
NAV = [("Start", "/"), ("Über uns", "/ueber-uns/"), ("Leistungen", "/leistungen/"),
       ("Galerie", "/galerie/"), ("Kontakt", "/kontakt/")]


def header(active):
    items = ""
    for label, href in NAV:
        cur = ' aria-current="page"' if href == active else ""
        items += f'<li><a href="{href}"{cur}>{E(label)}</a></li>'
    return (
        '<a class="skip" href="#inhalt">Zum Inhalt springen</a>'
        '<header class="header"><div class="wrap header__in">'
        f'<a class="logo" href="/" aria-label="Faden &amp; Form Atelier – zur Startseite">'
        f'<img src="{LOGO}" alt="Faden &amp; Form" width="459" height="180"></a>'
        '<button class="burger" type="button" aria-expanded="false" aria-controls="nav" aria-label="Menü öffnen">'
        '<span></span><span></span><span></span></button>'
        f'<nav class="nav" id="nav" aria-label="Hauptnavigation"><ul class="nav__list">{items}</ul>'
        f'{btn("Platz anfragen", "/kontakt/#anfrage", arrow=False)}</nav>'
        '</div></header>')


def footer():
    return (
        '<footer class="footer"><div class="wrap"><div class="footer__grid">'
        '<div class="footer__brand">'
        f'<img src="{LOGO}" alt="Faden &amp; Form" width="459" height="180" loading="lazy">'
        '<p>Nähkurse, Workshops und Reparaturen in Liestal. Damit deine Lieblingsstücke noch lange getragen werden.</p>'
        '</div>'
        '<div><h2>Atelier</h2><ul>'
        '<li><a href="/ueber-uns/">Über uns</a></li><li><a href="/leistungen/">Leistungen</a></li>'
        '<li><a href="/leistungen/#preise">Preise</a></li><li><a href="/galerie/">Vorher – nachher</a></li>'
        '<li><a href="/kontakt/">Kontakt &amp; Anfahrt</a></li></ul></div>'
        '<div><h2>Kontakt</h2><address>Faden &amp; Form Atelier<br>Musterstrasse 22<br>4410 Liestal<br>'
        '<a href="tel:+41610000000">061 000 00 00</a><br><a href="mailto:info@faden-form.ch">info@faden-form.ch</a></address></div>'
        '<div><h2>Öffnungszeiten</h2><dl class="footer__hours">'
        '<dt>Di–Fr</dt><dd>10:00–18:00 Uhr</dd><dt>Sa</dt><dd>10:00–16:00 Uhr</dd><dt>Mo, So</dt><dd>geschlossen</dd></dl>'
        f'{btn("Platz anfragen", "/kontakt/#anfrage", "green")}'
        '</div></div>'
        '<div class="footer__bottom"><p style="margin:0">© <span data-year>2026</span> Faden &amp; Form Atelier, Liestal</p>'
        '<ul><li><a href="/impressum/">Impressum</a></li><li><a href="/datenschutz/">Datenschutz</a></li></ul></div>'
        '</div></footer>'
        f'<button class="to-top" type="button" aria-label="Nach oben">{icon("up")}</button>')


def layout(path, title, desc, content, noindex=False, extra_head=""):
    robots = '<meta name="robots" content="noindex, follow">' if noindex else ""
    url = DOMAIN + path
    return f'''<!doctype html>
<html lang="de-CH">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{E(title)}</title>
<meta name="description" content="{E(desc)}">
{robots}<link rel="canonical" href="{url}">
<meta property="og:type" content="website">
<meta property="og:locale" content="de_CH">
<meta property="og:site_name" content="Faden &amp; Form Atelier">
<meta property="og:title" content="{E(title)}">
<meta property="og:description" content="{E(desc)}">
<meta property="og:url" content="{url}">
<meta property="og:image" content="{DOMAIN}{LOGO}">
<meta name="theme-color" content="#234638">
<link rel="icon" type="image/png" href="/assets/img/favicon.png">
<link rel="apple-touch-icon" href="/assets/img/favicon.png">
<link rel="stylesheet" href="/assets/css/style.css">
<script src="/assets/js/main.js" defer></script>
{extra_head}</head>
<body>
{header(path)}
<main id="inhalt">
{content}
</main>
{footer()}
</body>
</html>
'''


def write(path, text):
    full = os.path.join(OUT, path.strip("/"), "index.html") if not path.endswith(".html") else os.path.join(OUT, path.strip("/"))
    os.makedirs(os.path.dirname(full), exist_ok=True)
    with open(full, "w", encoding="utf-8") as f:
        f.write(text)


# ------------------------------------------------------------------ Startseite
LOCAL_BUSINESS = '''<script type="application/ld+json">
{"@context":"https://schema.org","@type":"LocalBusiness","name":"Faden & Form Atelier",
"description":"Nähkurse für Einsteiger, Workshops zum Ändern und Flicken von Kleidern und Reparaturservice in Liestal.",
"url":"https://faden-form.ch/","telephone":"+41 61 000 00 00","email":"info@faden-form.ch","foundingDate":"2023",
"image":"https://faden-form.ch/assets/img/01m379p1cmm4jakz0kkk3g57m0.png",
"address":{"@type":"PostalAddress","streetAddress":"Musterstrasse 22","postalCode":"4410","addressLocality":"Liestal","addressCountry":"CH"},
"openingHoursSpecification":[
{"@type":"OpeningHoursSpecification","dayOfWeek":["Tuesday","Wednesday","Thursday","Friday"],"opens":"10:00","closes":"18:00"},
{"@type":"OpeningHoursSpecification","dayOfWeek":"Saturday","opens":"10:00","closes":"16:00"}]}
</script>
'''


def home():
    services = [
        ("needle", "Nähkurs für Einsteiger", "Du lernst die Nähmaschine kennen, schneidest zu und nähst dein erstes eigenes Stück. Für Erwachsene und Jugendliche ab 14 Jahren.", "/leistungen/#kurse", "Zum Kurs"),
        ("shirt", "Workshop Ändern & Flicken", "Hose zu lang, Pulli mit Loch, Knopf ab? Du bringst deine Kleider mit und reparierst sie mit uns – an einem Nachmittag.", "/leistungen/#workshops", "Zum Workshop"),
        ("scissors", "Reparaturservice", "Keine Zeit, selbst zu nähen? Wir ersetzen Reissverschlüsse, flicken Risse, kürzen Säume und passen Kleider an.", "/leistungen/#reparatur", "Zur Reparatur"),
    ]
    cards = "".join(
        f'<article class="card"><span class="card__num">0{i}</span><div class="card__icon">{icon(ic)}</div>'
        f'<h3>{E(t)}</h3><p>{E(p)}</p><a class="textlink" href="{h}">{E(l)}{icon("arrow")}</a></article>'
        for i, (ic, t, p, h, l) in enumerate(services, 1))
    stats = "".join(
        f'<div class="stat"><div class="stat__num">{n}</div><p>{E(p)}</p></div>'
        for n, p in [("6", "Personen höchstens pro Kurs – so bleibt Zeit für jede Frage."),
                     ("14<span>+</span>", "Jahre: Ab diesem Alter bist du in den Einsteigerkursen dabei."),
                     ("1", "Nachmittag reicht, um deine Lieblingshose wieder tragbar zu machen."),
                     ("5", "Tage pro Woche ist das Atelier in Liestal für dich offen.")])
    content = f'''
<section class="hero">
  <svg class="hero-thread" viewBox="0 0 1440 800" preserveAspectRatio="none" aria-hidden="true"><path data-thread d="M-20 770 C 260 730, 420 800, 660 770 S 1000 640, 1200 690 S 1400 770, 1460 720"/></svg>
  <div class="wrap hero__grid">
    <div>
      {kicker("Nähatelier in Liestal")}
      <h1>Nähen lernen.<br><span class="accent">Flicken</span> statt wegwerfen.</h1>
      <p class="lead">In kleinen Kursen mit höchstens sechs Personen lernst du nähen, ändern und reparieren – gern direkt an deinen eigenen Kleidern. Oder du bringst uns dein Lieblingsstück, und wir machen es wieder tragbar.</p>
      <div class="btns">{btn("Kurse entdecken", "/leistungen/#kurse")}{btn("Reparatur anfragen", "/kontakt/#anfrage", "outline", False)}</div>
    </div>
    <div class="hero__art">
      {hero_art()}
      <div class="hero__badge" data-drift="0,-40,0"><b>6</b><span><strong>Plätze pro Kurs</strong>klein, persönlich, entspannt</span></div>
    </div>
  </div>
</section>
{marquee(["Nähkurse für Einsteiger", "Kleider ändern", "Löcher stopfen", "Reissverschlüsse ersetzen", "Säume kürzen", "Eigene Kleider mitbringen", "Länger tragen statt neu kaufen"])}
<section class="sec" id="angebot">
  <div class="wrap">
    <div class="sec-head">{kicker("Was wir machen")}<h2>Drei Wege zu Kleidern, die länger halten</h2>
    <p class="lead">Ob du selbst zur Nadel greifen willst oder lieber reparieren lässt: Bei uns findest du den passenden Weg.</p></div>
    <div class="cards">{cards}</div>
  </div>
</section>
<section class="sec sec--surface">
  <div class="wrap split">
    <div class="split__media">{compare("flicken", "denim", "Jeans vorher: aufgerissenes Knie", "Jeans nachher: Knie mit unterlegtem Flicken und Sashiko-Stichen", "Vorher-nachher-Vergleich verschieben")}</div>
    <div class="split__text">
      {kicker("Vorher – nachher")}
      <h2>Repariert heisst nicht zweite Wahl</h2>
      <p>Eine gut gesetzte Naht hält oft länger als der Stoff daneben. Wir zeigen dir, wie du Risse, Löcher und abgewetzte Stellen so reparierst, dass dein Kleidungsstück wieder jahrelang getragen werden kann – unauffällig oder bewusst sichtbar als Gestaltungselement.</p>
      <p>Zieh den Regler und sieh selbst, was aus einem aufgerissenen Jeansknie werden kann.</p>
      <div class="btns">{btn("Alle Vorher-nachher-Beispiele", "/galerie/", "green")}</div>
    </div>
  </div>
</section>
<section class="sec sec--dark">
  <div class="wrap">
    <div class="sec-head">{kicker("Darauf kannst du zählen")}<h2>Klein, persönlich und praktisch</h2></div>
    <div class="stats">{stats}</div>
  </div>
</section>
{split(scene("haende", "Illustration: Hände nähen einen Flicken auf eine Jeans", "leinen"), "Deine Kleider, deine Fragen",
       "Du darfst mitbringen, was dir am Herzen liegt",
       "<p>In unseren Workshops arbeitest du nicht an Übungsstoff, sondern an deinen eigenen Kleidern: der Jeans mit dem Loch, dem Pulli, der zu weit ist, der Jacke mit dem kaputten Reissverschluss.</p><p>So lernst du genau die Technik, die du brauchst – und gehst mit einem Stück nach Hause, das du am nächsten Tag wieder anziehen kannst.</p>",
       rev=True, note=("max. 6", "Personen pro Kurs – du bekommst die Hilfe, die du brauchst."),
       extra=f'<div class="btns">{btn("Workshops ansehen", "/leistungen/#workshops", "outline", False)}</div>')}
<section class="sec sec--surface">
  <div class="wrap">
    <div class="sec-head sec-head--center">{kicker("So einfach geht’s")}<h2>In drei Schritten ins Atelier</h2></div>
    {steps([("Angebot wählen", "Such dir einen Kurs oder Workshop aus – oder beschreib uns kurz, was an deinem Kleidungsstück kaputt ist."),
            ("Platz anfragen", "Schick uns deine Anfrage über das Formular oder ruf an. Wir bestätigen dir Termin und Platz persönlich."),
            ("Loslegen", "Bring deine Kleider mit. Maschinen, Werkzeug und Tee stehen bereit – den Rest machen wir zusammen.")])}
  </div>
</section>
{marquee(["Kaputt ist kein Grund", "Flicken ist Handwerk", "Nähen macht Freude", "Aus alt wird lange"], "terra marquee--reverse")}
{cta("Hast du ein Lieblingsstück, das wieder getragen werden will?", "Frag einen Kursplatz an oder erzähl uns, was repariert werden soll. Wir melden uns innert zwei Arbeitstagen bei dir.",
     [btn("Jetzt anfragen", "/kontakt/#anfrage"), btn("061 000 00 00", "tel:+41610000000", "outline", False)])}
'''
    write("/", layout("/", "Faden & Form Atelier – Nähkurse, Workshops & Reparaturen in Liestal",
                      "Nähen lernen in kleinen Gruppen mit max. 6 Personen, Kleider ändern und flicken im Workshop oder reparieren lassen – im Faden & Form Atelier in Liestal.",
                      content, extra_head=LOCAL_BUSINESS))


# ------------------------------------------------------------------ Über uns
def ueber_uns():
    values = "".join(
        f'<div class="value">{icon(ic, "value__icon")}<h3>{E(t)}</h3><p>{E(p)}</p></div>'
        for ic, t, p in [
            ("users", "Persönlich", "Höchstens sechs Personen pro Kurs. So sehen wir, wo du gerade stehst, und helfen genau dort weiter."),
            ("leaf", "Nachhaltig", "Jedes reparierte Kleidungsstück muss nicht neu gekauft werden. Wir zeigen dir, wie Reparaturen richtig lange halten."),
            ("spark", "Kreativ", "Flicken darf man sehen. Wir probieren gern Neues aus: sichtbare Stopfnähte, Kontrastflicken und clevere Änderungen."),
        ])
    content = f'''
{page_hero("Über uns", 'Ein Atelier für alle, die ihre Kleider <span style="color:var(--terra);font-style:italic">lieben</span>',
           "Faden & Form ist ein offener Ort in Liestal, an dem genäht, geflickt und gelernt wird – ohne Vorkenntnisse, ohne Druck und mit viel Freude am Handwerk.",
           f'<div class="btns">{btn("Unser Angebot", "/leistungen/")}</div>')}
{split(scene("stapel", "Illustration: Stapel reparierter und gefalteter Kleidungsstücke", "leinen"), "Unsere Geschichte",
       "Angefangen hat es mit einem Stapel Kleider zum Flicken",
       "<p>Im Freundeskreis kam immer wieder dieselbe Frage: «Kannst du mir das schnell flicken?» Aus einzelnen Abenden am Küchentisch wurde der Wunsch nach einem Ort, an dem man das Nähen richtig lernen kann.</p><p>2023 haben wir in Liestal das Faden & Form Atelier eröffnet. Heute nähen hier Menschen, die noch nie eine Maschine eingefädelt haben, neben solchen, die ihre Garderobe selbst umgestalten. Allen gemeinsam ist der Gedanke: Was gut gemacht ist, darf lange getragen werden.</p>",
       note=("seit 2023", "in Liestal – mit Nadel, Faden und viel Geduld."))}
{marquee(["Persönlich", "Nachhaltig", "Kreativ", "Geduldig", "Handgemacht in Liestal"])}
<section class="sec">
  <div class="wrap">
    <div class="sec-head">{kicker("Wofür wir stehen")}<h2>Unsere Werte</h2></div>
    <div class="values">{values}</div>
  </div>
</section>
{split(scene("maschine", "Illustration: Nähmaschine im Atelier", "creme"), "Das Team",
       "Menschen mit Nadel, Faden und viel Geduld",
       "<p>Hinter Faden & Form stehen Menschen mit Erfahrung in Schneiderei und Textilreparatur – und mit Freude daran, dieses Wissen weiterzugeben. Wir erklären lieber einmal mehr als einmal zu wenig und nehmen uns Zeit für jede Frage.</p><p>Von Dienstag bis Samstag triffst du uns im Atelier. Komm gern vorbei und schau dir die Räume an, bevor du dich für einen Kurs entscheidest.</p>",
       rev=True, surface=True, anchor="team",
       extra=f'<div class="btns">{btn("Atelier besuchen", "/kontakt/", "outline", False)}</div>')}
<section class="sec sec--dark">
  <div class="wrap">
    <div class="sec-head">{kicker("Arbeitsweise")}<h2>So arbeiten wir mit dir</h2>
    <p class="lead">Du nähst selbst – wir sind daneben. Vom ersten Stich bis zum fertigen Stück.</p></div>
    {steps([("Zuhören", "Wir fragen, was du lernen oder reparieren möchtest, und schauen uns dein Kleidungsstück genau an."),
            ("Zeigen", "Jeder Arbeitsschritt wird vorgemacht und erklärt – an Probestoff oder direkt an deinem Stück."),
            ("Selber machen", "Du nähst selbst. Wir korrigieren und geben Tipps, bis es sitzt."),
            ("Mitnehmen", "Du gehst mit einem fertigen Stück nach Hause – und weisst, wie du es nächstes Mal allein schaffst.")])}
  </div>
</section>
{cta("Lust, uns kennenzulernen?", "Schau während der Öffnungszeiten im Atelier vorbei oder frag direkt einen Kursplatz an.",
     [btn("Kontakt aufnehmen", "/kontakt/"), btn("Zu den Kursen", "/leistungen/#kurse", "outline", False)])}
'''
    write("/ueber-uns/", layout("/ueber-uns/", "Über uns – Faden & Form Atelier in Liestal",
                                "Seit 2023 zeigt Faden & Form in Liestal, wie Kleider genäht, geändert und repariert werden: persönlich, in kleinen Gruppen und mit viel Geduld.",
                                content))


# ------------------------------------------------------------------ Leistungen
def leistungen():
    def plan(name, meta, amount, per, feats, href, label, hl=False, badge=None):
        f = "".join(f"<li>{icon('check')}<span>{E(x)}</span></li>" for x in feats)
        b = f'<span class="price__badge">{E(badge)}</span>' if badge else ""
        return (f'<article class="price{" price--hl" if hl else ""}">{b}<h3>{E(name)}</h3><p class="price__meta">{E(meta)}</p>'
                f'<div class="price__amount"><small>CHF</small>{E(amount)}</div><p class="price__per">{E(per)}</p>'
                f'<ul>{f}</ul>{btn(label, href, "" if hl else "outline", False)}</article>')
    prices = (plan("Nähkurs für Einsteiger", "4 Abende à 3 Stunden", "290", "pro Person, ganzer Kurs",
                   ["max. 6 Personen", "ab 14 Jahren", "Nähmaschine wird gestellt", "Material für dein erstes Projekt", "Kursunterlagen zum Mitnehmen"],
                   "/kontakt/#anfrage", "Kursplatz anfragen")
              + plan("Workshop Ändern & Flicken", "1 Nachmittag à 4 Stunden", "95", "pro Person",
                     ["max. 6 Personen", "an deinen eigenen Kleidern", "Nähmaschine wird gestellt", "Garn, Flicken und Knöpfe inklusive"],
                     "/kontakt/#anfrage", "Workshop anfragen", hl=True, badge="Beliebt")
              + plan("Reparaturservice", "wir nähen für dich", "ab 15", "pro Stück, fixer Preis nach Ansicht",
                     ["Saum kürzen ab CHF 15", "Knopf, Naht oder kleiner Riss ab CHF 15", "Reissverschluss ersetzen ab CHF 35", "meist innert einer Woche fertig"],
                     "/kontakt/#anfrage", "Reparatur anfragen"))
    faqs = [
        ("Brauche ich Vorkenntnisse?", "Nein. Der Nähkurs für Einsteiger beginnt ganz am Anfang. Auch in den Workshops helfen wir dir, wenn du noch nie genäht hast."),
        ("Ab welchem Alter kann ich teilnehmen?", "Unsere Kurse richten sich an Erwachsene. Die Einsteigerkurse stehen auch Jugendlichen ab 14 Jahren offen."),
        ("Muss ich eine eigene Nähmaschine mitbringen?", "Nein, im Atelier stehen genügend Maschinen bereit. Wenn du deine eigene Maschine kennenlernen möchtest, darfst du sie aber gern mitbringen."),
        ("Welche Kleider kann ich in den Workshop mitbringen?", "Alles, was geändert oder geflickt werden soll: Hosen, Hemden, Pullover, Jacken und Röcke. Bei sehr dicken Stoffen wie Leder oder Wintermänteln sprich uns bitte vorher kurz an. Bring die Stücke gewaschen und trocken mit."),
        ("Was passiert, wenn ich einen Kursabend verpasse?", "Gib uns möglichst früh Bescheid. Wenn ein Platz frei ist, holst du den Abend in einem späteren Kurs nach."),
        ("Wie läuft eine Reparatur ab?", "Du bringst dein Kleidungsstück während der Öffnungszeiten vorbei. Wir schauen es gemeinsam an, nennen dir den fixen Preis und den Abholtermin. Bezahlt wird bei der Abholung."),
        ("Kann ich einen Kurs verschenken?", "Ja. Schreib uns, wir stellen dir gern einen Gutschein für einen Kurs oder Workshop aus."),
    ]
    faq_html = "".join(f"<details><summary>{E(q)}</summary><div><p>{E(a)}</p></div></details>" for q, a in faqs)
    chips = ('<nav class="chips" aria-label="Angebote auf dieser Seite">'
             '<a class="chip" href="#kurse">Nähkurs</a><a class="chip" href="#workshops">Workshop</a>'
             '<a class="chip" href="#reparatur">Reparatur</a><a class="chip" href="#preise">Preise</a><a class="chip" href="#fragen">Fragen</a></nav>')
    content = f'''
{page_hero("Leistungen", "Kurse, Workshops und Reparaturen",
           "Du willst selbst nähen lernen, deine Kleider an einem Nachmittag reparieren oder ein Stück in gute Hände geben? Hier findest du alle Angebote mit Ablauf und Preisen.", chips)}
{split(scene("maschine", "Illustration: Nähmaschine mit eingespanntem Stoff", "leinen"), "Nähkurs für Einsteiger",
       "Deine ersten Nähte – sicher und mit Freude",
       '<ul class="facts"><li>4 × 3 Stunden</li><li>max. 6 Personen</li><li>ab 14 Jahren</li></ul>'
       "<p>Der Kurs für alle, die bei null anfangen oder ihr Wissen auffrischen möchten. An vier Abenden lernst du:</p>"
       "<ul><li>die Nähmaschine einfädeln, einstellen und pflegen</li><li>gerade Nähte, Rundungen und Versäubern</li>"
       "<li>Schnittmuster lesen und Stoff zuschneiden</li><li>dein erstes eigenes Projekt, zum Beispiel eine Tasche oder ein Kissen</li></ul>"
       "<p>Maschinen und Material sind inbegriffen.</p>",
       anchor="kurse", extra=f'<div class="btns">{btn("Kursplatz anfragen", "/kontakt/#anfrage")}</div>')}
{split(scene("haende", "Illustration: Flicken wird auf eine Jeans genäht", "creme"), "Workshop Ändern & Flicken",
       "Bring deine Kleider mit – wir reparieren sie zusammen",
       '<ul class="facts"><li>1 Nachmittag, 4 Stunden</li><li>max. 6 Personen</li><li>eigene Kleider</li></ul>'
       "<p>Im Workshop arbeitest du an deinen eigenen Stücken. Du bringst mit, was geändert oder geflickt werden soll, und wir zeigen dir die passende Technik:</p>"
       "<ul><li>Hosen und Ärmel kürzen, Säume neu setzen</li><li>Löcher stopfen und Risse flicken – unsichtbar oder als Blickfang</li>"
       "<li>Knöpfe annähen und Druckknöpfe setzen</li><li>Reissverschlüsse ersetzen</li><li>Kleider enger oder weiter machen</li></ul>",
       rev=True, surface=True, anchor="workshops", note=("eigene Kleider", "Du nimmst dein Stück repariert wieder mit."),
       extra=f'<div class="btns">{btn("Workshop anfragen", "/kontakt/#anfrage")}</div>')}
{split(scene("werkzeug", "Illustration: Schere, Massband und Stecknadeln", "leinen"), "Reparaturservice",
       "Wir machen dein Lieblingsstück wieder tragbar",
       "<p>Du hast keine Zeit, selbst zu nähen? Bring dein Kleidungsstück während der Öffnungszeiten vorbei. Wir schauen es gemeinsam an und nennen dir den Preis und den Abholtermin.</p>"
       "<ul><li>Reissverschlüsse ersetzen</li><li>Säume kürzen und neu nähen</li><li>Risse, Löcher und offene Nähte reparieren</li>"
       "<li>Kleider ändern und anpassen</li><li>Futter ersetzen</li></ul><p>Die meisten Reparaturen sind innert einer Woche fertig.</p>",
       anchor="reparatur", extra=f'<div class="btns">{btn("Reparatur anfragen", "/kontakt/#anfrage", "green")}</div>')}
<section class="sec sec--surface" id="preise">
  <div class="wrap">
    <div class="sec-head sec-head--center">{kicker("Preise")}<h2>Klare Preise, alles inklusive</h2>
    <p class="lead" style="margin-inline:auto">Maschinen, Werkzeug, Garn und Getränke sind in allen Kursen inbegriffen.</p></div>
    <div class="prices">{prices}</div>
    <p class="note" style="text-align:center">Die Kursdaten erhältst du mit der Bestätigung deiner Anfrage. Gutscheine gibt es für jedes Angebot.</p>
  </div>
</section>
<section class="sec sec--dark">
  <div class="wrap">
    <div class="sec-head">{kicker("Ablauf")}<h2>Von der Anfrage bis zum fertigen Stück</h2></div>
    {steps([("Anfrage senden", "Wähle im Formular Kurs, Workshop oder Reparatur und nenne uns deinen Wunschtermin."),
            ("Bestätigung erhalten", "Wir melden uns innert zwei Arbeitstagen und reservieren dir deinen Platz verbindlich."),
            ("Vorbereiten", "Für Workshops bringst du die Kleider mit, an denen du arbeiten möchtest – gewaschen und trocken."),
            ("Nähen", "Im Atelier steht alles bereit. Du arbeitest, wir begleiten dich.")])}
  </div>
</section>
<section class="sec" id="fragen">
  <div class="narrow">
    <div class="sec-head">{kicker("Häufige Fragen")}<h2>Gut zu wissen</h2></div>
    <div class="faq">{faq_html}</div>
  </div>
</section>
{cta("Such dir deinen Platz aus", "Die Kurse sind klein und schnell voll. Frag deinen Wunschtermin am besten gleich an.",
     [btn("Platz anfragen", "/kontakt/#anfrage"), btn("061 000 00 00", "tel:+41610000000", "outline", False)])}
'''
    write("/leistungen/", layout("/leistungen/", "Leistungen & Preise – Nähkurse, Workshops, Reparaturen | Faden & Form",
                                 "Nähkurs für Einsteiger ab 14 Jahren, Workshops zum Ändern und Flicken deiner Kleider und Reparaturservice in Liestal – mit Ablauf und Preisen.",
                                 content))


# ------------------------------------------------------------------ Galerie
PAIRS = [
    ("flicken", "denim", "hosen", "Jeans mit Knieflicken", "Aufgerissenes Knie, unterlegt und mit Sashiko-Stichen verstärkt.",
     "Jeans vorher: aufgerissenes Knie mit ausgefransten Fäden", "Jeans nachher: Knie mit unterlegtem Flicken und sichtbaren Sashiko-Stichen"),
    ("stopfen", "wolle", "strick", "Wollpullover gestopft", "Mottenloch am Ellbogen, sichtbar gestopft in Creme und Tannengrün.",
     "Wollpullover vorher: Loch am Ellbogen", "Wollpullover nachher: Loch mit gewebter Stopfnaht in Kontrastfarben geschlossen"),
    ("reissverschluss", "jacke", "jacken", "Winterjacke, neuer Reissverschluss", "Der alte Reissverschluss ging unten auf – der neue schliesst sauber.",
     "Winterjacke vorher: Reissverschluss geht unten auseinander", "Winterjacke nachher: neu eingesetzter Reissverschluss mit frischer Steppnaht"),
    ("kragen", "hemd", "hemden", "Hemdkragen gewendet", "Abgewetzter Kragen gewendet und neu abgesteppt – wie neu.",
     "Hemd vorher: Kragen mit ausgefranster Kante", "Hemd nachher: gewendeter Kragen mit sauberer Doppelnaht"),
    ("saum", "hose", "hosen", "Anzughose gekürzt", "Ausgetretener Saum gekürzt und neu gesäumt, mit Handstich-Finish.",
     "Anzughose vorher: ausgetretener, ausgefranster Saum", "Anzughose nachher: auf passende Länge gekürzt und neu gesäumt"),
    ("traeger", "canvas", "taschen", "Rucksackträger verstärkt", "Ausgerissener Träger neu angenäht und mit Kreuzstich gesichert.",
     "Rucksack vorher: ausgerissener Träger", "Rucksack nachher: Träger neu angenäht und mit Box-Kreuzstich verstärkt"),
]


def galerie():
    items = ""
    for kind, fab, cat, title, text, alt_b, alt_a in PAIRS:
        b = repair(kind, "vorher", fab, label=alt_b)
        a = repair(kind, "nachher", fab, label=alt_a)
        items += (f'<article class="pair" data-cat="{cat}"><div class="pair__imgs">'
                  f'<button class="pair__btn" type="button" data-lb data-caption="{E(title)} – vorher" aria-label="{E(alt_b)} – gross anzeigen">{b}<span class="pair__tag">Vorher</span></button>'
                  f'<button class="pair__btn" type="button" data-lb data-caption="{E(title)} – nachher" aria-label="{E(alt_a)} – gross anzeigen">{a}<span class="pair__tag pair__tag--after">Nachher</span></button>'
                  f'</div><div class="pair__cap"><h3>{E(title)}</h3><p>{E(text)}</p></div></article>')
    filters = [("alle", "Alle"), ("hosen", "Hosen"), ("strick", "Strick"), ("jacken", "Jacken"), ("hemden", "Hemden"), ("taschen", "Taschen")]
    fb = "".join(f'<button type="button" data-filter="{k}" aria-pressed="{"true" if k == "alle" else "false"}">{E(l)}</button>' for k, l in filters)
    lightbox = (f'<div class="lightbox" role="dialog" aria-modal="true" aria-label="Bildansicht" aria-hidden="true">'
                f'<figure class="lightbox__fig"><div class="lightbox__img"></div><figcaption class="lightbox__cap"></figcaption></figure>'
                f'<button class="lb-btn lb-close" type="button" aria-label="Schliessen">{icon("close")}</button>'
                f'<button class="lb-btn lb-prev" type="button" aria-label="Vorheriges Bild">{icon("left")}</button>'
                f'<button class="lb-btn lb-next" type="button" aria-label="Nächstes Bild">{icon("right")}</button></div>')
    content = f'''
{page_hero("Galerie", 'Vorher – nachher: Kleider mit <span style="color:var(--terra);font-style:italic">zweitem Leben</span>',
           "Stücke aus dem Atelier, repariert von uns oder von Kursteilnehmenden. Jede Reparatur ist dafür gemacht, lange zu halten – klick auf ein Bild, um es gross anzusehen.")}
<section class="sec sec--tight">
  <div class="wrap split">
    <div class="split__media">{compare("stopfen", "wolle", "Wollpullover vorher: Loch am Ellbogen", "Wollpullover nachher: sichtbar gestopft", "Vorher-nachher-Vergleich verschieben")}</div>
    <div class="split__text">{kicker("Zum Ausprobieren")}<h2>Zieh den Regler</h2>
      <p>Ein Mottenloch ist kein Grund, einen guten Wollpullover wegzugeben. Mit einer gewebten Stopfnaht wird die Stelle sogar stabiler als vorher – und zum Hingucker.</p>
      <p>Diese Technik lernst du in unserem Workshop «Ändern & Flicken» an einem Nachmittag.</p>
      <div class="btns">{btn("Zum Workshop", "/leistungen/#workshops", "green")}</div></div>
  </div>
</section>
<section class="sec sec--surface" id="vorher-nachher">
  <div class="wrap">
    <div class="sec-head">{kicker("Reparaturen")}<h2>Aus dem Atelier</h2></div>
    <div class="gal-filter" role="group" aria-label="Galerie filtern">{fb}</div>
    <div class="gallery">{items}</div>
  </div>
</section>
{cta("Dein Stück könnte das nächste sein", "Bring es in den Workshop mit oder lass es von uns reparieren.",
     [btn("Reparatur anfragen", "/kontakt/#anfrage"), btn("Workshops ansehen", "/leistungen/#workshops", "outline", False)])}
{lightbox}
'''
    write("/galerie/", layout("/galerie/", "Galerie – Vorher-nachher-Reparaturen | Faden & Form Atelier",
                              "Vorher und nachher: reparierte Jeans, Pullover, Jacken, Hemden und Taschen aus dem Faden & Form Atelier in Liestal. So bleiben Kleider lange tragbar.",
                              content))


# ------------------------------------------------------------------ Kontakt
def kontakt():
    topics = ["Nähkurs für Einsteiger", "Workshop Ändern & Flicken", "Reparatur", "Gutschein", "Etwas anderes"]
    th = "".join(f'<label class="topic"><input type="radio" name="thema" value="{E(t)}"{" required" if i == 0 else ""}{" checked" if i == 0 else ""}><span>{E(t)}</span></label>'
                 for i, t in enumerate(topics))
    hours = [("2,3,4,5", "Dienstag – Freitag", "10:00–18:00 Uhr"), ("6", "Samstag", "10:00–16:00 Uhr"),
             ("1,0", "Montag, Sonntag", "geschlossen")]
    hrows = "".join(f'<tr data-day="{d}"><th scope="row">{E(n)}</th><td>{E(t)}</td></tr>' for d, n, t in hours)
    osm = "https://www.openstreetmap.org/export/embed.html?bbox=7.725%2C47.478%2C7.745%2C47.490&amp;layer=mapnik&amp;marker=47.4840%2C7.7350"
    content = f'''
{page_hero("Kontakt", "Schreib uns – wir freuen uns auf dich",
           "Frag einen Kursplatz an, reserviere einen Workshop oder beschreib uns deine Reparatur. Wir antworten dir innert zwei Arbeitstagen.")}
<section class="sec sec--tight" id="anfrage">
  <div class="wrap contact">
    <form class="form" action="/anfrage.php" method="post">
      <h2>Deine Anfrage</h2>
      <p style="color:var(--muted);margin:0">Je genauer du dein Anliegen beschreibst, desto schneller können wir dir einen Termin vorschlagen. Felder mit <span class="req">*</span> brauchen wir.</p>
      <div id="form-msg" class="form__msg" role="status" hidden></div>
      <div class="form__grid">
        <fieldset class="field field--full topics"><legend>Worum geht es? <span class="req">*</span></legend>{th}</fieldset>
        <div class="field"><label for="f-name">Dein Name <span class="req">*</span></label><input id="f-name" name="name" type="text" autocomplete="name" required maxlength="120"></div>
        <div class="field"><label for="f-email">Deine E-Mail-Adresse <span class="req">*</span></label><input id="f-email" name="email" type="email" autocomplete="email" required maxlength="160"></div>
        <div class="field"><label for="f-tel">Telefon <span class="opt">(freiwillig)</span></label><input id="f-tel" name="telefon" type="tel" autocomplete="tel" maxlength="40"></div>
        <div class="field"><label for="f-pers">Anzahl Personen</label><select id="f-pers" name="personen"><option>1</option><option>2</option><option>3</option><option>4</option><option>5</option><option>6</option></select></div>
        <div class="field field--full"><label for="f-datum">Wunschtermin <span class="opt">(freiwillig)</span></label><input id="f-datum" name="wunschtermin" type="text" maxlength="120" placeholder="z. B. Dienstagabend oder ab Mitte Oktober"></div>
        <div class="field field--full"><label for="f-msg">Deine Nachricht <span class="req">*</span></label><textarea id="f-msg" name="nachricht" required maxlength="4000" placeholder="Was möchtest du lernen oder reparieren lassen?"></textarea></div>
        <div class="hp" aria-hidden="true"><label for="f-web">Website</label><input id="f-web" name="website" type="text" tabindex="-1" autocomplete="off"></div>
        <label class="check field--full"><input type="checkbox" name="datenschutz" value="ja" required><span>Ich habe die <a href="/datenschutz/">Datenschutzerklärung</a> gelesen und bin einverstanden, dass meine Angaben zur Bearbeitung meiner Anfrage verwendet werden. <span class="req">*</span></span></label>
        <div class="field--full"><button class="btn" type="submit">Anfrage senden{icon("arrow")}</button></div>
      </div>
    </form>
    <div class="info">
      <div class="info__card">
        <h2>So erreichst du uns</h2>
        <div class="info__row">{icon("pin")}<div>Faden &amp; Form Atelier<br>Musterstrasse 22<br>4410 Liestal</div></div>
        <div class="info__row">{icon("phone")}<div><a href="tel:+41610000000">061 000 00 00</a></div></div>
        <div class="info__row">{icon("mail")}<div><a href="mailto:info@faden-form.ch">info@faden-form.ch</a></div></div>
      </div>
      <div class="info__card info__card--light">
        <h2>Öffnungszeiten</h2>
        <p class="open-state">Öffnungszeiten</p>
        <table class="hours"><caption class="sr">Öffnungszeiten des Ateliers</caption>{hrows}</table>
        <p style="color:var(--muted);font-size:.95rem;margin:16px 0 0">Kurse und Workshops finden auch ausserhalb dieser Zeiten statt, zum Beispiel am Abend. Die genauen Daten erhältst du mit der Bestätigung.</p>
      </div>
    </div>
  </div>
</section>
<section class="sec sec--surface" id="anfahrt">
  <div class="wrap">
    <div class="sec-head">{kicker("Anfahrt")}<h2>Mitten in Liestal</h2>
    <p class="lead">Das Atelier liegt zentral in Liestal an der Musterstrasse 22 und ist gut mit Bahn und Bus erreichbar.</p></div>
    <div class="map" data-src="{osm}">
      <div class="map__bg">{map_bg()}</div>
      <div class="map__consent"><div>
        <h3>Karte anzeigen</h3>
        <p>Die Karte wird von OpenStreetMap geladen. Dabei wird deine IP-Adresse an OpenStreetMap übermittelt. Mehr dazu in der <a href="/datenschutz/">Datenschutzerklärung</a>.</p>
        <button class="btn btn--green" type="button" data-map>Karte laden</button>
        <p style="margin:14px 0 0"><a href="https://www.openstreetmap.org/search?query=Musterstrasse%2022%2C%204410%20Liestal" target="_blank" rel="noopener">In OpenStreetMap öffnen</a></p>
      </div></div>
    </div>
  </div>
</section>
'''
    write("/kontakt/", layout("/kontakt/", "Kontakt & Anfrage – Faden & Form Atelier Liestal",
                              "Kursplatz, Workshop oder Reparatur anfragen: Faden & Form Atelier, Musterstrasse 22, 4410 Liestal. Offen Di–Fr 10–18 Uhr, Sa 10–16 Uhr.",
                              content))


# ------------------------------------------------------------------ Rechtliches
def legal(path, h1, title, desc, body):
    content = f'''
<section class="page-hero"><div class="narrow">{kicker("Rechtliches")}<h1>{E(h1)}</h1></div><div class="page-hero__stitch" aria-hidden="true"></div></section>
<section class="sec sec--tight"><div class="narrow legal">{body}</div></section>
'''
    write(path, layout(path, title, desc, content, noindex=True))


IMPRESSUM = '''
<h2>Kontaktadresse</h2>
<p>Faden &amp; Form Atelier<br>Musterstrasse 22<br>4410 Liestal<br>Schweiz</p>
<p>Telefon: <a href="tel:+41610000000">061 000 00 00</a><br>E-Mail: <a href="mailto:info@faden-form.ch">info@faden-form.ch</a></p>
<h2>Verantwortlich für den Inhalt</h2>
<p>Faden &amp; Form Atelier, Liestal</p>
<h2>Haftungsausschluss</h2>
<p>Wir prüfen die Inhalte dieser Website sorgfältig. Trotzdem übernehmen wir keine Gewähr für die inhaltliche Richtigkeit, Genauigkeit, Aktualität, Zuverlässigkeit und Vollständigkeit der Informationen. Haftungsansprüche wegen Schäden materieller oder immaterieller Art, die aus dem Zugriff auf oder der Nutzung beziehungsweise Nichtnutzung der veröffentlichten Informationen entstanden sind, werden ausgeschlossen, soweit das gesetzlich zulässig ist.</p>
<p>Preise und Kursdaten können sich ändern. Verbindlich ist die Bestätigung, die du nach deiner Anfrage von uns erhältst.</p>
<h2>Haftung für Links</h2>
<p>Verweise und Links auf Websites Dritter liegen ausserhalb unseres Verantwortungsbereichs. Der Zugriff und die Nutzung solcher Websites erfolgen auf eigene Gefahr.</p>
<h2>Urheberrechte</h2>
<p>Die Urheber- und alle anderen Rechte an Inhalten, Bildern, Illustrationen und anderen Dateien auf dieser Website gehören ausschliesslich Faden &amp; Form Atelier oder den ausdrücklich genannten Rechteinhabern. Für die Vervielfältigung ist die schriftliche Zustimmung der Rechteinhaber vorgängig einzuholen.</p>
'''

DATENSCHUTZ = '''
<p>Der Schutz deiner Personendaten ist uns wichtig. Hier erfährst du, welche Daten wir beim Besuch unserer Website und bei einer Anfrage bearbeiten, wofür wir das tun und welche Rechte du hast. Grundlage ist das Schweizer Bundesgesetz über den Datenschutz (DSG).</p>
<h2>Verantwortliche Stelle</h2>
<p>Faden &amp; Form Atelier<br>Musterstrasse 22<br>4410 Liestal<br>Schweiz<br>E-Mail: <a href="mailto:info@faden-form.ch">info@faden-form.ch</a><br>Telefon: 061 000 00 00</p>
<h2>Server-Logfiles</h2>
<p>Beim Aufruf unserer Website speichert der Webserver automatisch technische Angaben wie IP-Adresse, Datum und Uhrzeit, aufgerufene Seite, Browser und Betriebssystem. Diese Daten brauchen wir, um die Website sicher und stabil zu betreiben. Sie werden nicht mit anderen Daten zusammengeführt und nach spätestens 30 Tagen gelöscht, sofern sie nicht zur Aufklärung eines Sicherheitsvorfalls benötigt werden.</p>
<h2>Kontaktformular und Anfragen</h2>
<p>Wenn du uns über das Kontaktformular, per E-Mail oder Telefon kontaktierst, bearbeiten wir die Angaben, die du uns mitteilst – zum Beispiel Name, E-Mail-Adresse, Telefonnummer, Wunschtermin und Nachricht. Wir verwenden sie ausschliesslich, um deine Anfrage zu beantworten, deinen Kurs- oder Workshopplatz zu reservieren oder deine Reparatur abzuwickeln. Wir geben diese Daten nicht an Dritte weiter und löschen sie, sobald sie für diesen Zweck nicht mehr nötig sind und keine gesetzlichen Aufbewahrungspflichten bestehen.</p>
<h2>Cookies und Analyse</h2>
<p>Unsere Website setzt keine Cookies und verwendet keine Analyse- oder Werbedienste.</p>
<h2>Karte (OpenStreetMap)</h2>
<p>Auf der Kontaktseite kannst du eine Karte von OpenStreetMap einblenden. Die Karte wird erst geladen, wenn du auf «Karte laden» klickst. Dabei wird deine IP-Adresse an die OpenStreetMap Foundation (Vereinigtes Königreich) übermittelt, die die Kartendaten bereitstellt. Weitere Informationen findest du in der <a href="https://osmfoundation.org/wiki/Privacy_Policy" target="_blank" rel="noopener">Datenschutzerklärung der OpenStreetMap Foundation</a>.</p>
<h2>Schriften</h2>
<p>Wir laden keine Schriften von fremden Servern. Die Website verwendet die Schriften, die auf deinem Gerät bereits installiert sind.</p>
<h2>Hosting</h2>
<p>Unsere Website wird bei einem Hosting-Anbieter betrieben, der die Daten in unserem Auftrag und nach unseren Weisungen bearbeitet.</p>
<h2>Datensicherheit</h2>
<p>Wir schützen deine Daten mit angemessenen technischen und organisatorischen Massnahmen. Die Verbindung zu unserer Website ist verschlüsselt (HTTPS).</p>
<h2>Deine Rechte</h2>
<p>Du hast das Recht, Auskunft über deine bei uns gespeicherten Personendaten zu verlangen und sie berichtigen oder löschen zu lassen. Du kannst einer Bearbeitung widersprechen und deine Daten in einem gängigen Format herausverlangen. Schreib uns dafür an <a href="mailto:info@faden-form.ch">info@faden-form.ch</a>. Wenn du der Meinung bist, dass wir deine Daten nicht korrekt bearbeiten, kannst du dich an den Eidgenössischen Datenschutz- und Öffentlichkeitsbeauftragten (EDÖB) wenden.</p>
<h2>Änderungen</h2>
<p>Wir können diese Datenschutzerklärung anpassen, wenn sich unsere Website oder die rechtlichen Vorgaben ändern. Es gilt jeweils die hier veröffentlichte Fassung.</p>
<p><em>Stand: September 2026</em></p>
'''


def notfound():
    content = f'''
<section class="notfound"><div class="narrow">
<p class="num" aria-hidden="true">404</p>
<h1>Hier fehlt ein Faden</h1>
<p class="lead" style="margin-inline:auto">Die Seite, die du suchst, gibt es nicht (mehr). Vielleicht findest du hier weiter:</p>
<div class="btns" style="justify-content:center">{btn("Zur Startseite", "/")}{btn("Kontakt", "/kontakt/", "outline", False)}</div>
</div></section>
'''
    write("/404.html", layout("/404.html", "Seite nicht gefunden – Faden & Form Atelier",
                              "Diese Seite gibt es nicht. Zurück zur Startseite von Faden & Form Atelier in Liestal.", content, noindex=True))


def sitemap():
    urls = ["/", "/ueber-uns/", "/leistungen/", "/galerie/", "/kontakt/"]
    x = "".join(f"<url><loc>{DOMAIN}{u}</loc></url>" for u in urls)
    with open(os.path.join(OUT, "sitemap.xml"), "w", encoding="utf-8") as f:
        f.write(f'<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">{x}</urlset>\n')
    with open(os.path.join(OUT, "robots.txt"), "w", encoding="utf-8") as f:
        f.write(f"User-agent: *\nAllow: /\n\nSitemap: {DOMAIN}/sitemap.xml\n")


if __name__ == "__main__":
    home()
    ueber_uns()
    leistungen()
    galerie()
    kontakt()
    legal("/impressum/", "Impressum", "Impressum – Faden & Form Atelier",
          "Impressum und rechtliche Angaben von Faden & Form Atelier, Musterstrasse 22, 4410 Liestal.", IMPRESSUM)
    legal("/datenschutz/", "Datenschutzerklärung", "Datenschutzerklärung – Faden & Form Atelier",
          "Wie Faden & Form Atelier deine Personendaten bearbeitet: Kontaktformular, Server-Logfiles, Karte und deine Rechte nach Schweizer Datenschutzgesetz.",
          DATENSCHUTZ)
    notfound()
    sitemap()
    print("gebaut:", OUT)
