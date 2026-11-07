"""Captions: lokale Vorlagen (ohne API-Key) und Claude über die Anthropic API.

Regeln für jede Caption – egal woher sie kommt:
- kurz, trocken, leicht frech, auf Deutsch (optional Schweizerdeutsch)
- keine erfundenen Zitate oder Aussagen von Personen
- keine Angriffe auf Aussehen, Körper, Herkunft o. Ä.
Claude-Antworten, die das verletzen, werden verworfen und durch eine lokale
Vorlage ersetzt. Gleiches gilt bei Timeout, API-Fehler oder Ablehnung.
"""
from __future__ import annotations

import base64
import json
import logging
import random
import re
import time
from dataclasses import dataclass

from .meme import Caption, to_jpeg

log = logging.getLogger(__name__)

# ------------------------------------------------------------- Vorlagen
# Stile: brainrot (Standard, Deutsch), gsw (Schwiizer Brainrot), de (klassisch trocken).
# Eintrag: (oben, unten, format) – format: classic | pov
STYLES = {"brainrot": "Brainrot (Deutsch)", "gsw": "Brainrot (Schwiizerdütsch)", "de": "Klassisch trocken"}

TEMPLATES: dict[str, dict[str, list[tuple[str, str, str]]]] = {
    "brainrot": {
        "laugh": [
            ("ER HAT ES GECOOKED", "", "classic"),
            ("+1000 AURA", "für dieses lachen", "classic"),
            ("bro lacht seit 3 minuten", "über NICHTS", "classic"),
            ("POV: du hast den witz nicht gecheckt aber lachst trotzdem mit", "", "pov"),
            ("hahahahahahaha", "was war nochmal lustig", "classic"),
            ("POV: der eine witz der dich 2 jahre deines lebens kostet", "", "pov"),
            ("lachflash level: kritisch", "", "classic"),
        ],
        "smile": [
            ("dieses grinsen hat steuerhinterziehung begangen", "", "classic"),
            ("er weiss was.", "er sagt nix.", "classic"),
            ("POV: er hat gerade deinen döner gegessen", "", "pov"),
        ],
        "grimace": [
            ("gesicht.exe", "funktioniert nicht mehr", "classic"),
            ("kein gedanke", "nur rauschen", "classic"),
            ("POV: der kebab war doch zu scharf", "", "pov"),
            ("NPC beim laden der nächsten dialogzeile", "", "classic"),
            ("-500 AURA", "", "classic"),
        ],
        "gesture": [
            ("bro erklärt quantenphysik", "mit den händen", "classic"),
            ("er beschwört gerade was", "", "classic"),
            ("POV: du bist der lehrer und er muss aufs klo", "", "pov"),
            ("diese geste hatte 0 grund", "", "classic"),
        ],
        "multi": [
            ("alle gleichzeitig im ohio-modus", "", "classic"),
            ("der ganze call nach einem satz von dir", "", "classic"),
            ("POV: jemand sagt 'kurze frage'", "", "pov"),
            ("gruppenchat hat geschlossen den verstand verloren", "", "classic"),
        ],
        "scene": [
            ("vorher", "nachher (keiner weiss was passiert ist)", "classic"),
            ("plot twist", "den niemand bestellt hat", "classic"),
            ("POV: die kamera geht aus und die aura bleibt", "", "pov"),
        ],
        "loud": [
            ("bro hat das mikro gefressen", "", "classic"),
            ("POV: deine kopfhörer auf 100%", "", "pov"),
            ("lautstärke: ja", "", "classic"),
        ],
        "dry": [
            ("15 minuten call", "content: 0", "classic"),
            ("live aus dem NPC-gehege", "", "classic"),
            ("bro starrt seit 15 minuten in die kamera", "", "classic"),
            ("ich warte noch auf den witz", "seit 15 minuten", "classic"),
            ("dieser call hat die aura von einem toaster", "", "classic"),
            ("POV: ihr seid seit 15 minuten im call und keiner sagt was", "", "pov"),
        ],
        "silence": [("15 minuten und das highlight war stille.", "", "classic")],
    },
    "gsw": {
        "laugh": [
            ("ER HETS GCOOKED", "", "classic"),
            ("+1000 AURA", "für das lache", "classic"),
            ("bro lacht sit 3 minute", "über NÜT", "classic"),
            ("POV: du checksch de witz nöd aber lachsch trotzdem mit", "", "pov"),
            ("hahahahahahaha", "was isch nomal luschtig gsi", "classic"),
        ],
        "smile": [
            ("das grinse het steuerhinterziehig gmacht", "", "classic"),
            ("er weiss öppis.", "er seit nüt.", "classic"),
            ("POV: er het grad din döner gässe", "", "pov"),
        ],
        "grimace": [
            ("gsicht.exe", "reagiert nüme", "classic"),
            ("kei gedanke", "nur rausche", "classic"),
            ("POV: de kebab isch doch z scharf gsi", "", "pov"),
            ("-500 AURA", "", "classic"),
        ],
        "gesture": [
            ("bro erklärt quantephysik", "mit de händ", "classic"),
            ("er beschwört grad öppis", "", "classic"),
            ("die geste het 0 grund gha", "", "classic"),
        ],
        "multi": [
            ("alli glichzitig im ohio-modus", "", "classic"),
            ("de ganz call nach eim satz vo dir", "", "classic"),
            ("POV: öpper seit 'churzi frag'", "", "pov"),
        ],
        "scene": [
            ("vorher", "nachher (niemer weiss was passiert isch)", "classic"),
            ("plot twist", "wo niemer bstellt het", "classic"),
        ],
        "loud": [
            ("bro het s mikro gfrässe", "", "classic"),
            ("POV: dini chopfhörer uf 100%", "", "pov"),
        ],
        "dry": [
            ("15 minute call", "content: 0", "classic"),
            ("live us em NPC-gheg", "", "classic"),
            ("ich wart no uf de witz", "sit 15 minute", "classic"),
            ("de call het d aura vomene toaster", "", "classic"),
        ],
        "silence": [("15 minute und s highlight isch stilli gsi.", "", "classic")],
    },
    "de": {
        "laugh": [("Wenn der Witz erst nach drei Sekunden ankommt", "", "classic"),
                  ("Kollektives Lachen", "Grund: unbekannt", "classic")],
        "smile": [("Dieses Grinsen", "hat definitiv etwas vor", "classic")],
        "grimace": [("Gesichtsausdruck:", "Systemupdate wird installiert", "classic"),
                    ("Mimik auf Maximum", "Kontext auf Minimum", "classic")],
        "gesture": [("Erklärt mit Händen", "versteht trotzdem keiner", "classic"),
                    ("Gestik: olympisch", "", "classic")],
        "multi": [("Alle gleichzeitig", "Synchronschwimmen, aber im Call", "classic")],
        "scene": [("Vorher", "Nachher", "classic"), ("Die Lage hat sich", "geringfügig verändert", "classic")],
        "loud": [("Lautstärke: ja", "", "classic")],
        "dry": [("15 Minuten Call", "Highlight: dieser Frame", "classic"),
                ("Eine Viertelstunde", "Spannung wie beim Wasserkochen", "classic")],
        "silence": [("15 Minuten und das Highlight war Stille.", "", "classic")],
    },
}

# Vergleichs-Meme: Webcam-Gesicht links, Fratze rechts.
COMPARE_LABELS = {
    "brainrot": [("er im call:", "er innerlich:"), ("was die cam sieht:", "was wirklich abgeht:"),
                 ("ich:", "meine letzte gehirnzelle:"), ("erwartung:", "realität:")],
    "gsw": [("er im call:", "er innerlich:"), ("was d cam gseht:", "was würkli abgaht:"),
            ("ich:", "mini letscht hirnzelle:"), ("erwartig:", "realität:")],
    "de": [("Außen:", "Innen:")],
}
DOODLE_FOR = {"laugh": ["scream"], "loud": ["scream"], "multi": ["scream"], "grimace": ["cry", "shock"],
              "smile": ["smug"], "scene": ["shock"], "gesture": ["shock", "scream"], "dry": ["stare"],
              "silence": ["stare"]}

SILENCE_TEXT = {s: TEMPLATES[s]["silence"][0][0] for s in TEMPLATES}
DEFAULT_STYLE = "brainrot"

# Wörter, die auf Angriffe wegen Aussehen/Körper hindeuten -> Caption verwerfen.
_APPEARANCE = re.compile(
    r"\b(hässlich|haesslich|fett|dick(e|er|en)?|dünn|duenn|glatze|zähne|zaehne|nase|ohren|frisur|haare?|"
    r"pickel|falten|gewicht|übergewicht|figur|bauch|brille|aussehen|gesicht wie|schiel\w*|"
    r"wüescht|wüest|fätt|hautfarbe|behindert|spast\w*|mongo\w*)\b",
    re.IGNORECASE,
)
_QUOTE = re.compile(r"[\"„“”«»]([^\"„“”«»]{3,})[\"„“”«»]")


@dataclass
class CaptionResult:
    caption: Caption
    used_ai: bool
    reason: str = ""


def category_for(signals: dict[str, float], score: float, min_score: float = 0.9) -> str:
    if score < min_score:
        return "dry"
    weights = {"laugh": 3.0, "multi": 2.0, "scene": 1.5, "grimace": 1.5, "smile": 1.2, "loud": 1.2, "gesture": 1.0}
    best = max(signals.items(), key=lambda kv: kv[1] * weights.get(kv[0], 1.0), default=("dry", 0.0))
    return best[0] if best[1] > 0.15 and best[0] in TEMPLATES["brainrot"] else "dry"


class LocalCaptioner:
    def __init__(self, seed: int | None = None):
        self.rng = random.Random(seed)
        self._recent: list[str] = []

    def caption(self, category: str, style: str = DEFAULT_STYLE, allow_compare: bool = True) -> Caption:
        style = style if style in TEMPLATES else DEFAULT_STYLE
        rng = self.rng
        doodle = rng.choice(DOODLE_FOR.get(category, ["stare"]))
        brainrot = style != "de"
        if brainrot and allow_compare and category != "silence" and rng.random() < 0.35:
            left, right = rng.choice(COMPARE_LABELS[style])
            return Caption("", source="local", fmt="compare", doodle=doodle, left=left, right=right)
        pool = TEMPLATES[style].get(category) or TEMPLATES[style]["dry"]
        choices = [c for c in pool if c[0] not in self._recent] or pool
        top, bottom, fmt = rng.choice(choices)
        self._recent = (self._recent + [top])[-8:]
        if brainrot and fmt == "classic" and category in {"laugh", "multi", "loud", "grimace"} and rng.random() < 0.25:
            fmt = "deepfry"
        return Caption(top=top, bottom=bottom, source="local", fmt=fmt, doodle=doodle)


def validate(cap: Caption, transcript: str = "") -> str | None:
    """Gibt einen Ablehnungsgrund zurück oder None, wenn die Caption ok ist."""
    text = f"{cap.top} {cap.bottom} {cap.left} {cap.right}".strip()
    if not cap.top.strip() and not (cap.fmt == "compare" and cap.left and cap.right):
        return "leer"
    if max(len(cap.top), len(cap.bottom), len(cap.left), len(cap.right)) > 90:
        return "zu lang"
    if _APPEARANCE.search(text):
        return "Aussehen"
    for quoted in _QUOTE.findall(text):
        if not transcript or quoted.lower().strip(" .!?") not in transcript.lower():
            return "erfundenes Zitat"
    if re.search(r"\b(sagt|sagte|meint|meinte|hat gesagt|seit|het gseit)\b\s*:", text, re.IGNORECASE):
        return "zugeschriebene Aussage"
    return None


# ---------------------------------------------------------------- Claude
SYSTEM_PROMPT_BRAINROT = """Du machst Memes aus einem privaten Discord-Videocall unter Freunden.
Alle Teilnehmenden haben der Aufnahme zugestimmt und wollen Brainrot-Humor: richtig stupide, absurd,
Gen-Z/TikTok-Vibe, kleingeschrieben, null Boomer-Humor, keine Wortspiele, keine "Wenn man..."-Opa-Witze.

Gute Beispiele für den Ton:
- "ER HAT ES GECOOKED"
- "+1000 AURA" / "-500 AURA"
- "bro lacht seit 3 minuten" + "über NICHTS"
- "POV: der kebab war doch zu scharf"
- "live aus dem NPC-gehege"
- Vergleich: links "er im call:", rechts "er innerlich:" (rechts kommt eine krakelige Paint-Fratze)
Schlechte (cringe) Beispiele, so NICHT: "Lachen auf Kommando", "Niemand: ... Absolut niemand:", "Humor-Level heute".

Formate (wähle das lustigste):
- classic: fette Impact-Schrift oben (top) und optional unten (bottom)
- pov: "POV: ..." als TikTok-Textbox (nur top)
- compare: Webcam-Gesicht links neben Paint-Fratze rechts; nutze left/right als Labels, top optional leer;
  doodle = scream (Schreien), stare (leerer Blick), cry (Tränen), smug (Grinsen), shock (Schock) – passend zum Gesichtsausdruck
- deepfry: übersättigt mit Totenköpfen und rotem Kreis, für chaotische Momente

Regeln (hart):
- Sprache: {language}. Kurz: jede Zeile max. 50 Zeichen.
- Lustig über die Situation oder Reaktion – niemals über Aussehen, Körper, Gewicht, Kleidung, Haare, Alter,
  Herkunft, Geschlecht oder Behinderung. Die Paint-Fratze steht für das Gefühl, nicht für das Aussehen.
- Erfinde keine Aussagen oder Zitate. Keine Anführungszeichen, ausser wörtlich aus dem mitgelieferten Transkript.
- Keine Namen, keine Beleidigungen, nichts Sexuelles.
Antworte nur mit JSON nach Schema."""

SYSTEM_PROMPT_DRY = """Du schreibst Captions für Memes aus einem privaten Discord-Videocall unter Freunden.
Alle Teilnehmenden haben der Aufnahme zugestimmt.
- Kurz, trocken, leicht frech. Max. 50 Zeichen pro Zeile. Sprache: {language}. Format: classic.
- Beschreibe die Situation oder Reaktion, nicht die Personen. Keine Bemerkungen über Aussehen, Körper, Kleidung,
  Alter, Herkunft, Geschlecht oder Behinderung. Erfinde keine Aussagen oder Zitate. Keine Namen.
Antworte nur mit JSON nach Schema (left/right leer lassen, doodle beliebig)."""

LANG = {
    "brainrot": "Deutsch mit Internet-Slang (Schweizer Schreibweise: ss statt ß)",
    "gsw": "Schweizerdeutsch (Mundart, Zürcher Färbung) mit Internet-Slang",
    "de": "Hochdeutsch (Schweizer Schreibweise mit ss statt ß)",
}

_SCHEMA = {
    "type": "object",
    "properties": {
        "format": {"type": "string", "enum": ["classic", "pov", "compare", "deepfry"]},
        "top": {"type": "string"},
        "bottom": {"type": "string"},
        "left": {"type": "string"},
        "right": {"type": "string"},
        "doodle": {"type": "string", "enum": ["scream", "stare", "cry", "smug", "shock"]},
    },
    "required": ["format", "top", "bottom", "left", "right", "doodle"],
    "additionalProperties": False,
}
# Modelle, die server-seitige Refusal-Fallbacks ("default") unterstützen.
_FALLBACK_MODELS = {"claude-opus-5-5", "claude-opus-5", "claude-fable-5-1", "claude-sonnet-5-5"}


class ClaudeCaptioner:
    def __init__(self, api_key: str, model: str = "claude-opus-5-5", effort: str = "low"):
        import anthropic  # type: ignore

        self.anthropic = anthropic
        self.client = anthropic.Anthropic(api_key=api_key, max_retries=0)
        self.model = model
        self.effort = effort

    def caption(self, frames: list, category: str, signals: dict[str, float], style: str,
                transcript: str = "", timeout: float = 20.0, allow_compare: bool = True) -> Caption:
        content: list[dict] = []
        for img in frames[:3]:
            content.append({
                "type": "image",
                "source": {"type": "base64", "media_type": "image/jpeg",
                           "data": base64.standard_b64encode(to_jpeg(img)).decode("ascii")},
            })
        sig = ", ".join(f"{k}={v:.2f}" for k, v in sorted(signals.items()) if v > 0.05) or "kaum Signale"
        text = (
            f"Erkannte Signale (lokal, 0..1): {sig}. Dominante Kategorie: {category}.\n"
            + ("Bei zwei Bildern: erstes = Auslöser, zweites = Reaktion.\n" if len(frames) > 1 else "")
            + (f"Transkript-Ausschnitt (lokal erkannt, kann Fehler enthalten): {transcript}\n" if transcript else "Kein Transkript.\n")
            + ("Ein Gesicht ist im Fokus (compare möglich).\n" if allow_compare else "Mehrere Leute/ganzer Call im Bild (kein compare).\n")
            + "Mach das Meme."
        )
        content.append({"type": "text", "text": text})
        kwargs: dict = {}
        if self.model in _FALLBACK_MODELS:
            kwargs["extra_headers"] = {"anthropic-beta": "server-side-fallback-2026-07-01"}
            kwargs["extra_body"] = {"fallbacks": "default"}
        resp = self.client.with_options(timeout=timeout).messages.create(
            model=self.model,
            max_tokens=2000,
            system=(SYSTEM_PROMPT_DRY if style == "de" else SYSTEM_PROMPT_BRAINROT).format(
                language=LANG.get(style, LANG["brainrot"])),
            messages=[{"role": "user", "content": content}],
            output_config={"effort": self.effort, "format": {"type": "json_schema", "schema": _SCHEMA}},
            **kwargs,
        )
        if resp.stop_reason == "refusal":
            raise RuntimeError("Claude hat abgelehnt")
        raw = next((b.text for b in resp.content if getattr(b, "type", "") == "text"), "")
        data = json.loads(raw)
        fmt = str(data.get("format", "classic"))
        if fmt == "compare" and not allow_compare:
            fmt = "classic"
        get = lambda k: str(data.get(k, "")).strip()  # noqa: E731
        return Caption(top=get("top"), bottom=get("bottom"), source="claude", fmt=fmt,
                       doodle=get("doodle") or "stare", left=get("left"), right=get("right"))


class CaptionService:
    """Wählt Claude oder lokale Vorlagen und fällt bei jedem Problem lokal zurück."""

    def __init__(self, mode: str = "auto", api_key: str = "", model: str = "claude-opus-5-5",
                 effort: str = "low", seed: int | None = None, claude: object | None = None):
        self.mode = mode
        self.local = LocalCaptioner(seed)
        self.claude = claude
        self.last_error = ""
        if self.claude is None and api_key and mode in {"auto", "claude"}:
            try:
                self.claude = ClaudeCaptioner(api_key, model, effort)
            except Exception as exc:  # anthropic nicht installiert o. Ä.
                self.last_error = f"Claude nicht verfügbar: {exc}"
                log.warning(self.last_error)

    @property
    def ai_enabled(self) -> bool:
        return self.claude is not None and self.mode in {"auto", "claude"}

    def make(self, frames: list, category: str, signals: dict[str, float], style: str,
             transcript: str = "", timeout: float = 20.0, allow_compare: bool = True) -> CaptionResult:
        """Blockierend (läuft im Worker-Thread). Gibt immer eine Caption zurück."""
        if self.ai_enabled and timeout >= 1.5 and frames:
            started = time.monotonic()
            try:
                cap = self.claude.caption(frames, category, signals, style, transcript, timeout,  # type: ignore[union-attr]
                                          allow_compare=allow_compare)
                reason = validate(cap, transcript)
                if reason is None:
                    self.last_error = ""
                    return CaptionResult(cap, True)
                self.last_error = f"Claude-Caption verworfen ({reason})"
            except Exception as exc:
                self.last_error = f"Claude nicht nutzbar ({type(exc).__name__}: {str(exc)[:80]})"
            log.warning("%s nach %.1fs – lokale Vorlage", self.last_error, time.monotonic() - started)
            cap = self.local.caption(category, style, allow_compare)
            cap.note = self.last_error
            return CaptionResult(cap, False, self.last_error)
        return CaptionResult(self.local.caption(category, style, allow_compare), False, "lokaler Modus")
