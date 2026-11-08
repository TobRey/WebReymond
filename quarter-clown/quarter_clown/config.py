"""Konfiguration aus .env laden.

Secrets (Bot-Token, API-Key) liegen nur in der lokalen Datei `.env`, die nie
committet wird. Der Takt ist im Normalbetrieb fest auf 15 Minuten verdrahtet –
es gibt bewusst keine Umgebungsvariable, die ihn verändert.
"""
from __future__ import annotations

import json
import os
import sys
from dataclasses import dataclass, field
from pathlib import Path

try:
    from dotenv import load_dotenv
except ImportError:  # pragma: no cover - dotenv ist in requirements.txt
    load_dotenv = None

FROZEN = bool(getattr(sys, "frozen", False))      # als Setup.exe installiert (PyInstaller)
APP_ROOT = Path(__file__).resolve().parent.parent
ASSETS = Path(__file__).resolve().parent / "assets"
APP_NAME = "Quarter Clown"


def user_dir() -> Path:
    """Wo Einstellungen, Zustand, Testausgaben und eigene Sticker liegen."""
    if FROZEN or os.getenv("QC_USER_DIR"):
        base = Path(os.getenv("QC_USER_DIR") or Path(os.getenv("LOCALAPPDATA") or Path.home() / "AppData" / "Local") / "QuarterClown")
    else:
        base = APP_ROOT
    base.mkdir(parents=True, exist_ok=True)
    return base


def _build_server_url() -> str:
    try:  # wird beim Release-Build von GitHub Actions erzeugt
        from ._build import SERVER_URL  # type: ignore

        return SERVER_URL
    except Exception:
        return ""


def default_server_url() -> str:
    return (os.getenv("QC_SERVER_URL") or _build_server_url()).strip().rstrip("/")

# Fester Takt. Normalbetrieb: 15 Minuten. Testmodus: 30 Sekunden.
NORMAL_INTERVAL_S = 15 * 60
TEST_INTERVAL_S = 30
TEST_INTERVALS = (10, 30, 60)   # erlaubte Takte für /quarter testmodus

# Discord-Berechtigungen für den Invite-Link:
# View Channel (1024) + Send Messages (2048) + Embed Links (16384)
# + Attach Files (32768) + Read Message History (65536)
BOT_PERMISSIONS = 1024 + 2048 + 16384 + 32768 + 65536


def _bool(name: str, default: bool = False) -> bool:
    raw = os.getenv(name)
    if raw is None or raw.strip() == "":
        return default
    return raw.strip().lower() in {"1", "true", "yes", "ja", "on"}


def _int(name: str, default: int | None = None) -> int | None:
    raw = (os.getenv(name) or "").strip()
    if not raw:
        return default
    try:
        return int(raw)
    except ValueError:
        return default


def _float(name: str, default: float) -> float:
    raw = (os.getenv(name) or "").strip()
    try:
        return float(raw) if raw else default
    except ValueError:
        return default


@dataclass
class Timing:
    """Zeitparameter eines Blocks."""

    interval: float
    lead: float          # so viele Sekunden vor dem Stichtag wird das Meme vorbereitet
    margin: float        # Sicherheitsabstand: spätestens hier muss das Meme fertig sein
    ai_timeout: float    # maximale Wartezeit auf die Claude-API
    post_grace: float    # wie lange nach dem Stichtag ein Post noch versucht wird

    @classmethod
    def normal(cls) -> "Timing":
        return cls(interval=NORMAL_INTERVAL_S, lead=120.0, margin=4.0, ai_timeout=30.0, post_grace=60.0)

    @classmethod
    def test(cls) -> "Timing":
        return cls.for_interval(TEST_INTERVAL_S)

    @classmethod
    def for_interval(cls, interval: float) -> "Timing":
        """Verkürzter Takt für Testläufe (10 s, 30 s, 1 min …)."""
        lead = max(6.0, min(120.0, interval * 0.3))
        margin = 4.0 if interval >= 300 else (0.5 if interval <= 15 else 1.0)
        return cls(interval=float(interval), lead=lead, margin=margin,
                   ai_timeout=min(30.0, lead - margin - 1.0), post_grace=min(60.0, interval * 0.25))


@dataclass
class Settings:
    discord_token: str = ""
    discord_guild_id: int | None = None
    admin_role: str = "Quarter Clown Admin"

    anthropic_api_key: str = ""
    anthropic_model: str = "claude-opus-5-5"
    anthropic_effort: str = "low"
    caption_mode: str = "auto"       # auto | claude | local
    caption_style: str = "brainrot"  # brainrot | gsw | de

    capture_source: str = "window"   # window | obs
    window_title: str = "Discord"
    obs_host: str = "localhost"
    obs_port: int = 4455
    obs_password: str = ""
    obs_source: str = "Discord"
    capture_fps: float = 4.0

    audio_enabled: bool = True
    audio_device: str = ""           # Teilstring des Ausgabegeräts, leer = Standard
    mic_enabled: bool = False
    transcribe: bool = False
    whisper_model: str = "small"

    test_mode: bool = False
    test_channel_id: int | None = None
    sample_path: str = ""            # Video/Bilderordner für den Testmodus, leer = synthetisch

    data_dir: Path = field(default_factory=lambda: user_dir() / "data")
    output_dir: Path = field(default_factory=lambda: user_dir() / "output")
    save_posted: bool = False

    # Verbindung zum zentralen Quarter-Clown-Server (Standard für Kunden)
    server_url: str = ""
    link_token: str = ""
    linked_guild: str = ""

    @property
    def hosted(self) -> bool:
        """Gehosteter Modus: kein eigener Bot-Token, sondern Verbindung zum Quarter-Clown-Server."""
        return not self.discord_token

    @property
    def settings_file(self) -> Path:
        return self.data_dir / "settings.json"

    def save_user(self) -> None:
        """Speichert die in der App gemachten Einstellungen (Kopplung, API-Key) lokal."""
        self.data_dir.mkdir(parents=True, exist_ok=True)
        data = {"link_token": self.link_token, "linked_guild": self.linked_guild,
                "anthropic_api_key": self._user_api_key, "server_url": self._user_server_url}
        tmp = self.settings_file.with_suffix(".tmp")
        tmp.write_text(json.dumps(data, indent=2), encoding="utf-8")
        os.replace(tmp, self.settings_file)

    def set_api_key(self, key: str) -> None:
        self._user_api_key = key.strip()
        self.anthropic_api_key = os.getenv("ANTHROPIC_API_KEY", "").strip() or self._user_api_key
        self.save_user()

    _user_api_key: str = ""
    _user_server_url: str = ""

    @property
    def timing(self) -> Timing:
        return Timing.test() if self.test_mode else Timing.normal()

    @property
    def has_api_key(self) -> bool:
        return bool(self.anthropic_api_key.strip())


def load_settings(test_mode: bool = False, env_file: Path | None = None) -> Settings:
    if load_dotenv is not None:
        load_dotenv(env_file or APP_ROOT / ".env", override=False)
        load_dotenv(user_dir() / ".env", override=False)
    s = Settings(
        discord_token=os.getenv("DISCORD_BOT_TOKEN", "").strip(),
        discord_guild_id=_int("DISCORD_GUILD_ID"),
        admin_role=os.getenv("QC_ADMIN_ROLE", "Quarter Clown Admin").strip() or "Quarter Clown Admin",
        anthropic_api_key=os.getenv("ANTHROPIC_API_KEY", "").strip(),
        anthropic_model=os.getenv("ANTHROPIC_MODEL", "claude-opus-5-5").strip() or "claude-opus-5-5",
        anthropic_effort=os.getenv("ANTHROPIC_EFFORT", "low").strip() or "low",
        caption_mode=os.getenv("QC_CAPTION_MODE", "auto").strip().lower() or "auto",
        caption_style=os.getenv("QC_CAPTION_STYLE", "brainrot").strip().lower() or "brainrot",
        capture_source=os.getenv("QC_CAPTURE_SOURCE", "window").strip().lower() or "window",
        window_title=os.getenv("QC_WINDOW_TITLE", "Discord").strip() or "Discord",
        obs_host=os.getenv("OBS_HOST", "localhost").strip() or "localhost",
        obs_port=_int("OBS_PORT", 4455) or 4455,
        obs_password=os.getenv("OBS_PASSWORD", ""),
        obs_source=os.getenv("OBS_SOURCE", "Discord").strip() or "Discord",
        capture_fps=_float("QC_CAPTURE_FPS", 4.0),
        audio_enabled=_bool("QC_AUDIO", True),
        audio_device=os.getenv("QC_AUDIO_DEVICE", "").strip(),
        mic_enabled=_bool("QC_MIC", False),
        transcribe=_bool("QC_TRANSCRIBE", False),
        whisper_model=os.getenv("QC_WHISPER_MODEL", "small").strip() or "small",
        test_mode=test_mode,
        test_channel_id=_int("QC_TEST_CHANNEL_ID"),
        sample_path=os.getenv("QC_SAMPLE_PATH", "").strip(),
        save_posted=_bool("QC_SAVE_POSTED", False),
    )
    if s.caption_style not in {"brainrot", "gsw", "de"}:
        s.caption_style = "brainrot"
    if s.caption_mode not in {"auto", "claude", "local"}:
        s.caption_mode = "auto"
    s.data_dir.mkdir(parents=True, exist_ok=True)
    user: dict = {}
    if s.settings_file.exists():
        try:
            user = json.loads(s.settings_file.read_text(encoding="utf-8"))
        except (OSError, json.JSONDecodeError):
            user = {}
    s._user_api_key = str(user.get("anthropic_api_key", ""))
    s._user_server_url = str(user.get("server_url", ""))
    s.anthropic_api_key = s.anthropic_api_key or s._user_api_key
    s.link_token = str(user.get("link_token", ""))
    s.linked_guild = str(user.get("linked_guild", ""))
    s.server_url = (os.getenv("QC_SERVER_URL") or s._user_server_url or default_server_url()).strip().rstrip("/")
    return s


def invite_url(application_id: int | str) -> str:
    return (
        "https://discord.com/oauth2/authorize"
        f"?client_id={application_id}&scope=bot+applications.commands&permissions={BOT_PERMISSIONS}"
    )
