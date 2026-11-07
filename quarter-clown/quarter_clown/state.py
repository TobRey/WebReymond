"""Persistenter Zustand (JSON, atomar geschrieben).

Wichtig für die Doppelpost-Sperre: `last_posted_block` wird vor und nach jedem
Post gesichert. Nach einem Neustart oder einer Wiederverbindung wird ein Block
nie zweimal gepostet, und verpasste Blöcke werden nicht nachgeschickt.
"""
from __future__ import annotations

import json
import os
import tempfile
import threading
import time
import uuid
from dataclasses import asdict, dataclass, field
from pathlib import Path
from typing import Any


@dataclass
class GuildConfig:
    guild_id: int | None = None
    guild_name: str = ""
    voice_channel_id: int | None = None
    voice_channel_name: str = ""
    text_channel_id: int | None = None
    text_channel_name: str = ""
    test_channel_id: int | None = None
    test_channel_name: str = ""


@dataclass
class SessionRecord:
    id: str = ""
    t0: float = 0.0               # bestätigter Sessionstart (Unix-Zeit)
    interval: float = 900.0
    test_mode: bool = False
    channel_id: int | None = None
    active: bool = False
    last_posted_block: int = 0
    posting_block: int = 0        # Block, der gerade gesendet wird (Absturz-Marker)
    stopped_at: float = 0.0
    stop_reason: str = ""
    skipped_blocks: list[int] = field(default_factory=list)

    @staticmethod
    def new(t0: float, interval: float, test_mode: bool, channel_id: int | None) -> "SessionRecord":
        return SessionRecord(
            id=uuid.uuid4().hex[:8], t0=t0, interval=interval, test_mode=test_mode,
            channel_id=channel_id, active=True,
        )


@dataclass
class Prefs:
    caption_style: str = ""
    caption_mode: str = "auto"
    mic_enabled: bool = False
    window_title: str = ""
    capture_source: str = "window"


class StateStore:
    """Kleiner thread-sicherer JSON-Speicher."""

    def __init__(self, path: Path):
        self.path = Path(path)
        self._lock = threading.RLock()
        self.guild = GuildConfig()
        self.session = SessionRecord()
        self.prefs = Prefs()
        self._load()

    # ------------------------------------------------------------------ io
    def _load(self) -> None:
        if not self.path.exists():
            return
        try:
            raw = json.loads(self.path.read_text(encoding="utf-8"))
        except (OSError, json.JSONDecodeError):
            return
        self.guild = _fill(GuildConfig, raw.get("guild"))
        self.session = _fill(SessionRecord, raw.get("session"))
        self.prefs = _fill(Prefs, raw.get("prefs"))

    def save(self) -> None:
        with self._lock:
            self.path.parent.mkdir(parents=True, exist_ok=True)
            data = {"guild": asdict(self.guild), "session": asdict(self.session), "prefs": asdict(self.prefs)}
            fd, tmp = tempfile.mkstemp(prefix=".state-", dir=str(self.path.parent))
            with os.fdopen(fd, "w", encoding="utf-8") as fh:
                json.dump(data, fh, indent=2)
                fh.flush()
                os.fsync(fh.fileno())
            os.replace(tmp, self.path)

    # ------------------------------------------------------------- session
    def start_session(self, t0: float, interval: float, test_mode: bool, channel_id: int | None) -> SessionRecord:
        with self._lock:
            self.session = SessionRecord.new(t0, interval, test_mode, channel_id)
            self.save()
            return self.session

    def stop_session(self, reason: str) -> None:
        with self._lock:
            if self.session.active:
                self.session.active = False
                self.session.stopped_at = time.time()
                self.session.stop_reason = reason
                self.save()

    def may_post(self, session_id: str, block: int) -> bool:
        with self._lock:
            s = self.session
            return s.active and s.id == session_id and block > s.last_posted_block

    def mark_posting(self, session_id: str, block: int) -> bool:
        """Reserviert einen Block für das Senden. False = schon erledigt/gestoppt."""
        with self._lock:
            if not self.may_post(session_id, block):
                return False
            self.session.posting_block = block
            self.save()
            return True

    def mark_posted(self, session_id: str, block: int) -> None:
        with self._lock:
            if self.session.id == session_id and block > self.session.last_posted_block:
                self.session.last_posted_block = block
                self.session.posting_block = 0
                self.save()

    def mark_skipped(self, session_id: str, block: int) -> None:
        with self._lock:
            if self.session.id == session_id:
                self.session.skipped_blocks = (self.session.skipped_blocks + [block])[-50:]
                # Ein übersprungener Block gilt als erledigt: nie nachschicken.
                if block > self.session.last_posted_block:
                    self.session.last_posted_block = block
                self.session.posting_block = 0
                self.save()


def _fill(cls: type, raw: Any):
    obj = cls()
    if isinstance(raw, dict):
        for key, value in raw.items():
            if hasattr(obj, key):
                setattr(obj, key, value)
    return obj
