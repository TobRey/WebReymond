"""Windows-App (tkinter): Live-Vorschau, Countdown, Zielkanal, Aufnahmestatus, Start/Stop."""
from __future__ import annotations

import asyncio
import io
import logging
import threading
import tkinter as tk
import webbrowser
from tkinter import messagebox, ttk

from PIL import Image, ImageTk

from .captions import STYLES
from .capture.windows import IS_WINDOWS, enable_dpi_awareness, list_windows
from .config import ASSETS, Settings
from .session import Controller, Phase

log = logging.getLogger(__name__)

BG = "#1E1F22"
CARD = "#2B2D31"
CARD2 = "#313338"
TEXT = "#F2F3F5"
MUTED = "#B5BAC1"
LIME = "#B6FF2E"
PURPLE = "#A23BFF"
RED = "#F23F43"
FONT = "Segoe UI" if IS_WINDOWS else "DejaVu Sans"


def start_backend(controller: Controller, settings: Settings) -> threading.Thread:
    """asyncio-Loop (Bot-Verbindung + Takt) in eigenem Thread starten."""
    ready = threading.Event()

    def runner() -> None:
        loop = asyncio.new_event_loop()
        asyncio.set_event_loop(loop)
        controller.loop = loop

        async def boot() -> None:
            controller.attach_bot()
            ready.set()

        loop.create_task(boot())
        loop.run_forever()

    th = threading.Thread(target=runner, name="qc-async", daemon=True)
    th.start()
    ready.wait(5)
    return th


class App:
    def __init__(self, settings: Settings):
        enable_dpi_awareness()
        self.settings = settings
        self.ctl = Controller(settings)
        start_backend(self.ctl, settings)

        self.root = tk.Tk()
        self.root.title("Quarter Clown" + (" – Testmodus" if settings.test_mode else ""))
        self.root.configure(bg=BG)
        self.root.geometry("1220x800")
        self.root.minsize(1060, 700)
        try:
            if IS_WINDOWS:
                self.root.iconbitmap(str(ASSETS / "icon.ico"))
            else:
                self._icon = ImageTk.PhotoImage(Image.open(ASSETS / "logo.png").resize((64, 64)))
                self.root.iconphoto(True, self._icon)
        except Exception:
            pass
        self._style()
        self._windows: list = []
        self._preview_img = None
        self._meme_img = None
        self._meme_shown = -1
        self._build()
        self.refresh_windows()
        self.root.protocol("WM_DELETE_WINDOW", self.on_close)
        self.root.after(200, self.tick)
        if self.ctl.needs_pairing and not settings.test_mode:
            self.root.after(400, self.open_wizard)

    # ------------------------------------------------------------------ ui
    def _style(self) -> None:
        st = ttk.Style(self.root)
        st.theme_use("clam")
        st.configure(".", background=CARD, foreground=TEXT, fieldbackground=CARD2, font=(FONT, 10))
        st.configure("TCombobox", fieldbackground=CARD2, background=CARD2, foreground=TEXT, arrowcolor=LIME,
                     bordercolor=CARD2, lightcolor=CARD2, darkcolor=CARD2)
        st.map("TCombobox", fieldbackground=[("readonly", CARD2)], foreground=[("readonly", TEXT)],
               selectbackground=[("readonly", CARD2)], selectforeground=[("readonly", TEXT)])
        st.configure("TCheckbutton", background=CARD, foreground=TEXT, indicatorcolor=CARD2)
        st.map("TCheckbutton", indicatorcolor=[("selected", LIME)], background=[("active", CARD)])
        st.configure("TRadiobutton", background=CARD, foreground=TEXT, indicatorcolor=CARD2)
        st.map("TRadiobutton", indicatorcolor=[("selected", LIME)], background=[("active", CARD)])
        self.root.option_add("*TCombobox*Listbox.background", CARD2)
        self.root.option_add("*TCombobox*Listbox.foreground", TEXT)
        self.root.option_add("*TCombobox*Listbox.selectBackground", PURPLE)

    def _card(self, parent, **grid) -> tk.Frame:
        f = tk.Frame(parent, bg=CARD, highlightthickness=0, padx=16, pady=14)
        f.grid(**grid)
        return f

    def _label(self, parent, text="", size=10, color=TEXT, bold=False, **kw) -> tk.Label:
        return tk.Label(parent, text=text, bg=parent["bg"], fg=color,
                        font=(FONT, size, "bold" if bold else "normal"), **kw)

    def _build(self) -> None:
        root = self.root
        root.columnconfigure(0, weight=3)
        root.columnconfigure(1, weight=2, minsize=500)
        root.rowconfigure(1, weight=1)

        # Kopfzeile
        head = tk.Frame(root, bg=BG, padx=18, pady=12)
        head.grid(row=0, column=0, columnspan=2, sticky="ew")
        try:
            self._logo = ImageTk.PhotoImage(Image.open(ASSETS / "logo.png").resize((54, 54), Image.LANCZOS))
            tk.Label(head, image=self._logo, bg=BG).pack(side="left")
        except Exception:
            pass
        titles = tk.Frame(head, bg=BG)
        titles.pack(side="left", padx=12)
        self._label(titles, "QUARTER CLOWN", 20, LIME, True).pack(anchor="w")
        self._label(titles, "Alle 15 Minuten genau ein Meme aus eurem Call", 10, MUTED).pack(anchor="w")
        self.test_badge = tk.Label(head, text="", bg=PURPLE, fg="white", font=(FONT, 10, "bold"), pady=4)
        if self.settings.test_mode:
            self.test_badge.configure(text="  TESTMODUS · 30-s-Takt · Beispielmaterial  ")
            self.test_badge.pack(side="right")
        self._small_button(head, "⚙ Einstellungen", self.open_settings).pack(side="right")
        self.bot_label = self._label(head, "Bot: verbinde …", 10, MUTED)
        self.bot_label.pack(side="right", padx=14)

        # Linke Spalte: Vorschau + Log
        left = tk.Frame(root, bg=BG, padx=18)
        left.grid(row=1, column=0, sticky="nsew")
        left.columnconfigure(0, weight=1)
        left.rowconfigure(0, weight=3)
        left.rowconfigure(1, weight=1)
        pv = self._card(left, row=0, column=0, sticky="nsew", pady=(0, 12))
        pv.columnconfigure(0, weight=1)
        pv.rowconfigure(1, weight=1)
        self._label(pv, "LIVE-VORSCHAU", 9, MUTED, True).grid(row=0, column=0, sticky="w")
        self.preview = tk.Label(pv, bg="#111214", fg=MUTED, font=(FONT, 11),
                                text="Vorschau startet, sobald alle zugestimmt haben.")
        self.preview.grid(row=1, column=0, sticky="nsew", pady=(8, 0))
        self.capture_note = self._label(pv, "", 9, "#FFB547")
        self.capture_note.grid(row=2, column=0, sticky="w", pady=(6, 0))

        lg = self._card(left, row=1, column=0, sticky="nsew", pady=(0, 18))
        lg.columnconfigure(0, weight=1)
        lg.rowconfigure(1, weight=1)
        self._label(lg, "PROTOKOLL", 9, MUTED, True).grid(row=0, column=0, sticky="w")
        self.logbox = tk.Text(lg, height=8, bg="#111214", fg=MUTED, bd=0, font=("Consolas" if IS_WINDOWS else "DejaVu Sans Mono", 9),
                              wrap="word", state="disabled", padx=8, pady=6)
        self.logbox.grid(row=1, column=0, sticky="nsew", pady=(8, 0))

        # Rechte Spalte
        right = tk.Frame(root, bg=BG, padx=0)
        right.grid(row=1, column=1, sticky="nsew", padx=(0, 18))
        right.columnconfigure(0, weight=1)

        cd = self._card(right, row=0, column=0, sticky="ew", pady=(0, 12))
        self.countdown = self._label(cd, "Nächster Drop in --:--", 26, LIME, True, anchor="w")
        self.countdown.pack(anchor="w")
        self.status_label = self._label(cd, "■ Gestoppt", 13, MUTED, True)
        self.status_label.pack(anchor="w", pady=(6, 0))
        self.target_label = self._label(cd, "Zielkanal: –", 10, TEXT, anchor="w", wraplength=440, justify="left")
        self.target_label.pack(anchor="w", pady=(6, 0))
        self.ai_label = self._label(cd, "", 9, MUTED)
        self.ai_label.pack(anchor="w", pady=(2, 0))
        self.consent_label = self._label(cd, "", 10, TEXT, justify="left")
        self.consent_label.pack(anchor="w", pady=(6, 0))

        ctl = self._card(right, row=1, column=0, sticky="ew", pady=(0, 12))
        ctl.columnconfigure(1, weight=1)
        r = 0
        if not self.settings.test_mode:
            self._label(ctl, "Quelle", 10, MUTED).grid(row=r, column=0, sticky="w", pady=3)
            self.source_var = tk.StringVar(value=self.ctl.capture_kind)
            srcf = tk.Frame(ctl, bg=CARD)
            srcf.grid(row=r, column=1, columnspan=2, sticky="w")
            ttk.Radiobutton(srcf, text="Discord-Fenster", value="window", variable=self.source_var,
                            command=self.on_source).pack(side="left")
            ttk.Radiobutton(srcf, text="OBS", value="obs", variable=self.source_var, command=self.on_source).pack(side="left", padx=10)
            r += 1
            self._label(ctl, "Fenster", 10, MUTED).grid(row=r, column=0, sticky="w", pady=3)
            self.window_var = tk.StringVar()
            self.window_combo = ttk.Combobox(ctl, textvariable=self.window_var, state="readonly")
            self.window_combo.grid(row=r, column=1, sticky="ew", padx=(8, 4))
            self.window_combo.bind("<<ComboboxSelected>>", lambda _e: self.on_source())
            btns = tk.Frame(ctl, bg=CARD)
            btns.grid(row=r, column=2)
            self._small_button(btns, "↻", self.refresh_windows).pack(side="left")
            self._small_button(btns, "Prüfen", self.check_window).pack(side="left", padx=(4, 0))
            r += 1
        else:
            self._label(ctl, f"Quelle: {self.ctl._make_source().name}", 10, TEXT).grid(row=r, column=0, columnspan=3, sticky="w")
            r += 1

        self._label(ctl, "Caption-Stil", 10, MUTED).grid(row=r, column=0, sticky="w", pady=3)
        self.style_var = tk.StringVar(value=STYLES.get(self.ctl.style, STYLES["brainrot"]))
        cb = ttk.Combobox(ctl, textvariable=self.style_var, values=list(STYLES.values()), state="readonly")
        cb.grid(row=r, column=1, columnspan=2, sticky="ew", padx=(8, 0))
        cb.bind("<<ComboboxSelected>>", lambda _e: self.ctl.set_style(
            next(k for k, v in STYLES.items() if v == self.style_var.get())))
        r += 1
        self._label(ctl, "Captions", 10, MUTED).grid(row=r, column=0, sticky="w", pady=3)
        modes = {"auto": "Claude, sonst lokal", "claude": "Claude (Fallback lokal)", "local": "Nur lokale Vorlagen"}
        self._modes = modes
        self.mode_var = tk.StringVar(value=modes.get(self.ctl.caption_mode, modes["auto"]))
        mc = ttk.Combobox(ctl, textvariable=self.mode_var, values=list(modes.values()), state="readonly")
        mc.grid(row=r, column=1, columnspan=2, sticky="ew", padx=(8, 0))
        mc.bind("<<ComboboxSelected>>", lambda _e: self.ctl.set_caption_mode(
            next(k for k, v in modes.items() if v == self.mode_var.get())))
        r += 1
        if not self.settings.test_mode:
            self.mic_var = tk.BooleanVar(value=self.ctl.mic_enabled)
            ttk.Checkbutton(ctl, text="Mein Mikrofon dazuschalten", variable=self.mic_var,
                            command=lambda: self.ctl.set_mic(self.mic_var.get())).grid(row=r, column=0, columnspan=3, sticky="w", pady=(6, 0))
            r += 1

        self.start_btn = tk.Button(ctl, text="▶  START", command=self.on_start_stop, bg=LIME, fg=BG,
                                   activebackground="#D4FF7A", activeforeground=BG, bd=0, relief="flat",
                                   font=(FONT, 16, "bold"), pady=10, cursor="hand2")
        self.start_btn.grid(row=r, column=0, columnspan=3, sticky="ew", pady=(14, 0))
        r += 1
        tr = tk.Frame(ctl, bg=CARD)
        tr.grid(row=r, column=0, columnspan=3, sticky="ew", pady=(10, 0))
        self._label(tr, "Testlauf:", 10, MUTED).pack(side="left")
        self.test_takt = tk.StringVar(value="10 s")
        ttk.Combobox(tr, textvariable=self.test_takt, values=["10 s", "30 s", "60 s"], width=6,
                     state="readonly").pack(side="left", padx=6)
        self.test_src = tk.StringVar(value="Beispielmaterial")
        ttk.Combobox(tr, textvariable=self.test_src, values=["Beispielmaterial", "Live-Call"], width=16,
                     state="readonly").pack(side="left")
        self._small_button(tr, "🧪 5 Test-Drops", self.on_test_run).pack(side="left", padx=6)
        r += 1
        self.invite_btn = self._small_button(ctl, "Bot-Invite-Link öffnen", self.open_invite)
        self.invite_btn.grid(row=r, column=0, columnspan=3, sticky="w", pady=(10, 0))

        mm = self._card(right, row=2, column=0, sticky="nsew", pady=(0, 18))
        self._label(mm, "LETZTER DROP", 9, MUTED, True).pack(anchor="w")
        self.meme_label = tk.Label(mm, bg=CARD, fg=MUTED, text="Noch kein Meme", font=(FONT, 10))
        self.meme_label.pack(anchor="w", pady=(8, 0))

    def _small_button(self, parent, text, cmd) -> tk.Button:
        return tk.Button(parent, text=text, command=cmd, bg=CARD2, fg=TEXT, activebackground=PURPLE,
                         activeforeground="white", bd=0, relief="flat", padx=10, pady=4, font=(FONT, 9, "bold"), cursor="hand2")

    # ------------------------------------------------------------- actions
    def refresh_windows(self) -> None:
        if self.settings.test_mode:
            return
        wins = list_windows()
        wins.sort(key=lambda w: (self.settings.window_title.lower() not in w.title.lower(), w.title.lower()))
        self._windows = wins
        self.window_combo["values"] = [w.label() for w in wins]
        pref = self.ctl.window_title or self.settings.window_title
        for w in wins:
            if pref and pref.lower() in w.title.lower():
                self.window_var.set(w.label())
                self.on_source()
                break
        if not wins:
            self.window_var.set("Keine Fenster gefunden (nur unter Windows)")

    def _selected_window(self):
        label = self.window_var.get()
        return next((w for w in self._windows if w.label() == label), None)

    def on_source(self) -> None:
        if self.ctl.session_active:
            return
        kind = self.source_var.get()
        w = self._selected_window()
        self.ctl.set_source(kind, w.hwnd if w else None, w.title if w else "")

    def check_window(self) -> None:
        self.on_source()
        img = self.ctl.snapshot_once()
        if img is None:
            messagebox.showwarning("Quarter Clown", self.ctl.capture_note or "Kein Bild.")
            return
        self._show_preview(img)

    def on_start_stop(self) -> None:
        if self.ctl.session_active:
            if messagebox.askyesno("Quarter Clown", "Aufnahme sofort stoppen? Danach wird nichts mehr gepostet."):
                self.ctl.submit(self.ctl.stop("Stopp in der Windows-App"))
            return
        if not self.settings.test_mode:
            self.on_source()
        fut = self.ctl.submit(self.ctl.request_start())
        if fut is not None:
            fut.add_done_callback(lambda f: self.root.after(0, self._start_result, f))

    def on_test_run(self) -> None:
        if self.ctl.session_active:
            messagebox.showinfo("Quarter Clown", "Es läuft schon eine Session.")
            return
        if not self.settings.test_mode:
            self.on_source()
        interval = int(self.test_takt.get().split()[0])
        live = self.test_src.get() == "Live-Call"
        fut = self.ctl.submit(self.ctl.request_test(interval=interval, sample=not live, max_drops=5))
        if fut is not None:
            fut.add_done_callback(lambda f: self.root.after(0, self._start_result, f))

    def _start_result(self, fut) -> None:
        try:
            msg = fut.result()
        except Exception as exc:
            msg = f"Fehler: {exc}"
        self.ctl.log(msg)
        if self.ctl.phase == Phase.IDLE:
            messagebox.showinfo("Quarter Clown", msg)

    def open_invite(self) -> None:
        url = self.ctl.status().get("invite")
        if not url and self.settings.server_url:
            url = self._server_info().get("invite_url") or self.settings.server_url
        if url:
            webbrowser.open(url)
        else:
            messagebox.showinfo("Quarter Clown", "Kein Invite-Link verfügbar – der Server ist nicht erreichbar.")

    def _server_info(self) -> dict:
        from .remote import fetch_info

        fut = self.ctl.submit(fetch_info(self.settings.server_url))
        try:
            return fut.result(timeout=8) if fut else {}
        except Exception:
            return {}

    # ------------------------------------------------------- Assistent
    def open_wizard(self) -> None:
        """Erster Start: Bot einladen, /quarter setup, Code eingeben – fertig."""
        if getattr(self, "_wizard", None) is not None and self._wizard.winfo_exists():
            self._wizard.lift()
            return
        w = tk.Toplevel(self.root, bg=BG, padx=28, pady=24)
        self._wizard = w
        w.title("Quarter Clown einrichten")
        w.transient(self.root)
        w.resizable(False, False)
        try:
            self._wiz_logo = ImageTk.PhotoImage(Image.open(ASSETS / "logo.png").resize((84, 84), Image.LANCZOS))
            tk.Label(w, image=self._wiz_logo, bg=BG).grid(row=0, column=0, rowspan=2, sticky="n", padx=(0, 16))
        except Exception:
            pass
        tk.Label(w, text="Willkommen bei Quarter Clown!", bg=BG, fg=LIME, font=(FONT, 18, "bold")).grid(row=0, column=1, sticky="w")
        tk.Label(w, text="In drei Schritten bist du startklar.", bg=BG, fg=MUTED, font=(FONT, 11)).grid(row=1, column=1, sticky="w")

        def step(row, num, title, text):
            f = tk.Frame(w, bg=CARD, padx=16, pady=12)
            f.grid(row=row, column=0, columnspan=2, sticky="ew", pady=(14, 0))
            tk.Label(f, text=num, bg=PURPLE, fg="white", font=(FONT, 13, "bold"), width=2).pack(side="left", anchor="n")
            body = tk.Frame(f, bg=CARD, padx=12)
            body.pack(side="left", fill="x", expand=True)
            tk.Label(body, text=title, bg=CARD, fg=TEXT, font=(FONT, 12, "bold")).pack(anchor="w")
            tk.Label(body, text=text, bg=CARD, fg=MUTED, font=(FONT, 10), justify="left", wraplength=430).pack(anchor="w", pady=(2, 6))
            return body

        b1 = step(2, "1", "Bot zu deinem Discord-Server hinzufügen",
                  "Öffnet Discord im Browser: Server auswählen und „Autorisieren“ klicken.")
        tk.Button(b1, text="Bot einladen", command=self.open_invite, bg=LIME, fg=BG, bd=0, padx=14, pady=6,
                  font=(FONT, 10, "bold"), cursor="hand2").pack(anchor="w")
        step(3, "2", "In Discord einrichten",
             "Tippe in einem Textkanal  /quarter setup  und wähle Voice-Channel und Meme-Kanal. "
             "Der Bot zeigt dir einen Code wie  ABCD-1234.")
        b3 = step(4, "3", "Code hier eingeben", "Danach verbindet sich die App bei jedem Start automatisch.")
        row = tk.Frame(b3, bg=CARD)
        row.pack(anchor="w")
        code = tk.StringVar()
        entry = tk.Entry(row, textvariable=code, font=("Consolas" if IS_WINDOWS else "DejaVu Sans Mono", 16), width=11,
                         bg=CARD2, fg=LIME, insertbackground=LIME, bd=0, justify="center")
        entry.pack(side="left", ipady=6)
        status = tk.Label(b3, text="", bg=CARD, fg="#FFB547", font=(FONT, 10), wraplength=430, justify="left")

        def done(fut) -> None:
            try:
                guild = fut.result()
            except Exception as exc:
                status.configure(text=str(exc), fg="#FFB547")
                btn.configure(state="normal")
                return
            status.configure(text=f"✅ Verbunden mit {guild}!", fg=LIME)
            w.after(1200, w.destroy)
            w.after(1300, lambda: messagebox.showinfo(
                "Quarter Clown", f"Fertig! Verbunden mit {guild}.\n\nZum Ausprobieren in Discord:\n"
                "/quarter testmodus takt:10 Sekunden\n\nFür echte Calls: Discord-Fenster wählen und ▶ START."))

        def connect() -> None:
            if len(code.get().replace("-", "").strip()) < 8:
                status.configure(text="Der Code hat 8 Zeichen, z. B. ABCD-1234.")
                return
            btn.configure(state="disabled")
            status.configure(text="Verbinde …", fg=MUTED)
            fut = self.ctl.submit(self.ctl.pair(code.get()))
            if fut is not None:
                fut.add_done_callback(lambda f: self.root.after(0, done, f))

        btn = tk.Button(row, text="Verbinden", command=connect, bg=PURPLE, fg="white", bd=0, padx=14, pady=8,
                        font=(FONT, 10, "bold"), cursor="hand2")
        btn.pack(side="left", padx=(10, 0))
        status.pack(anchor="w", pady=(8, 0))
        entry.bind("<Return>", lambda _e: connect())
        if not self.settings.server_url:
            status.configure(text="Diese Version kennt keinen Quarter-Clown-Server (QC_SERVER_URL fehlt).")
        tk.Button(w, text="Später", command=w.destroy, bg=BG, fg=MUTED, bd=0, font=(FONT, 9), cursor="hand2"
                  ).grid(row=5, column=1, sticky="e", pady=(14, 0))
        entry.focus_set()

    # --------------------------------------------------- Einstellungen
    def open_settings(self) -> None:
        w = tk.Toplevel(self.root, bg=BG, padx=24, pady=20)
        w.title("Quarter Clown – Einstellungen")
        w.transient(self.root)
        w.resizable(False, False)
        tk.Label(w, text="Einstellungen", bg=BG, fg=LIME, font=(FONT, 16, "bold")).grid(row=0, column=0, columnspan=2, sticky="w")
        r = 1
        if self.settings.hosted:
            linked = self.settings.linked_guild or "nicht gekoppelt"
            tk.Label(w, text=f"Discord-Server: {linked}", bg=BG, fg=TEXT, font=(FONT, 10)).grid(row=r, column=0, sticky="w", pady=(12, 0))
            tk.Button(w, text="Neu koppeln", command=lambda: (w.destroy(), self.open_wizard()), bg=CARD2, fg=TEXT, bd=0,
                      padx=10, pady=4, cursor="hand2").grid(row=r, column=1, sticky="e", pady=(12, 0))
            r += 1
        tk.Label(w, text="Claude-API-Key (optional – sonst lokale Brainrot-Vorlagen)", bg=BG, fg=MUTED,
                 font=(FONT, 10)).grid(row=r, column=0, columnspan=2, sticky="w", pady=(16, 2))
        r += 1
        key = tk.StringVar(value=self.settings._user_api_key)
        tk.Entry(w, textvariable=key, show="•", width=48, bg=CARD2, fg=TEXT, insertbackground=TEXT, bd=0
                 ).grid(row=r, column=0, sticky="w", ipady=5)
        tk.Button(w, text="Speichern", command=lambda: (self.ctl.set_api_key(key.get()), messagebox.showinfo(
            "Quarter Clown", "Gespeichert.", parent=w)), bg=PURPLE, fg="white", bd=0, padx=10, pady=4,
            cursor="hand2").grid(row=r, column=1, sticky="e", padx=(8, 0))
        r += 1
        tk.Label(w, text="Key gibt es auf console.anthropic.com – er bleibt nur auf diesem PC.", bg=BG, fg=MUTED,
                 font=(FONT, 9)).grid(row=r, column=0, columnspan=2, sticky="w")
        r += 1
        tk.Label(w, text="Eigene Reaktionsbilder für Vergleichs-Memes", bg=BG, fg=MUTED, font=(FONT, 10)
                 ).grid(row=r, column=0, sticky="w", pady=(16, 0))
        tk.Button(w, text="Sticker-Ordner öffnen", command=self.open_stickers, bg=CARD2, fg=TEXT, bd=0, padx=10, pady=4,
                  cursor="hand2").grid(row=r, column=1, sticky="e", pady=(16, 0))

    def open_stickers(self) -> None:
        import os
        import subprocess
        import sys

        from .doodles import STICKER_DIR

        for sub in ("scream", "stare", "cry", "smug", "shock"):
            (STICKER_DIR / sub).mkdir(parents=True, exist_ok=True)
        if sys.platform.startswith("win"):
            os.startfile(str(STICKER_DIR))  # type: ignore[attr-defined]
        else:
            subprocess.Popen(["xdg-open", str(STICKER_DIR)])

    def on_close(self) -> None:
        if self.ctl.session_active and not messagebox.askyesno("Quarter Clown", "Session läuft. Stoppen und beenden?"):
            return
        fut = self.ctl.submit(self.ctl.shutdown())
        try:
            if fut:
                fut.result(timeout=5)
        except Exception:
            pass
        if self.ctl.bot is not None and self.ctl.loop is not None:
            try:
                asyncio.run_coroutine_threadsafe(self.ctl.bot.close(), self.ctl.loop).result(timeout=5)
            except Exception:
                pass
        self.root.destroy()

    # ---------------------------------------------------------------- tick
    def _show_preview(self, img: Image.Image) -> None:
        w = max(320, self.preview.winfo_width() - 4)
        h = max(180, self.preview.winfo_height() - 4)
        im = img.copy()
        im.thumbnail((w, h))
        self._preview_img = ImageTk.PhotoImage(im)
        self.preview.configure(image=self._preview_img, text="")

    def tick(self) -> None:
        st = self.ctl.status()
        phase = st["phase"]
        if st["countdown"] is not None and phase != Phase.AWAITING.value:
            sec = int(st["countdown"] + 0.999)
            self.countdown.configure(text=f"Nächster Drop in {sec // 60}:{sec % 60:02d}", fg=LIME)
        elif phase == Phase.AWAITING.value:
            self.countdown.configure(text="Warte auf Zustimmung …", fg=PURPLE)
        else:
            self.countdown.configure(text="Nächster Drop in --:--", fg=MUTED)
        color = {"running": LIME, "paused": "#FFB547", "awaiting": PURPLE}.get(phase, MUTED)
        self.status_label.configure(text=st["phase_label"], fg=color)
        self.target_label.configure(text=f"Zielkanal: {st['target'] or 'nicht eingerichtet – /quarter setup'}")
        ai = f"Captions: {st['ai']}"
        if st["ai_error"]:
            ai += f" · letzter Fallback: {st['ai_error']}"
        self.ai_label.configure(text=ai[:110])
        self.consent_label.configure(text="\n".join(f"{'✅' if p['ok'] else '⏳'} {p['name']}" for p in st["participants"]))
        bot = st.get("bot_label") or ("Bot: online" if st["bot_ready"] else "Bot: offline")
        self.bot_label.configure(text=bot[:60], fg=LIME if st["bot_ready"] else MUTED)
        self.capture_note.configure(text=st["capture_note"])
        if not self.settings.test_mode:
            if st["test_label"]:
                self.test_badge.configure(text=f"  {st['test_label']}  ")
                if not self.test_badge.winfo_ismapped():
                    self.test_badge.pack(side="right")
            elif self.test_badge.winfo_ismapped():
                self.test_badge.pack_forget()
        self.start_btn.configure(
            text="■  STOP" if self.ctl.session_active else "▶  START",
            bg=RED if self.ctl.session_active else LIME, fg="white" if self.ctl.session_active else BG)

        with self.ctl.preview_lock:
            prev = self.ctl.preview
        if prev is not None and st["recording"]:
            self._show_preview(prev)
        elif phase == Phase.PAUSED.value:
            self.preview.configure(image="", text="⏸ Pausiert – warte auf Zustimmung")
        elif phase == Phase.IDLE.value and getattr(self, "_last_phase", "idle") != "idle":
            self._preview_img = None
            self.preview.configure(image="", text="■ Gestoppt – Vorschau gelöscht.")
        self._last_phase = phase

        if self.ctl.last_meme is not None and self._meme_shown != self.ctl.last_meme_block:
            self._meme_shown = self.ctl.last_meme_block
            im = Image.open(io.BytesIO(self.ctl.last_meme.png))
            im.thumbnail((360, 300))
            self._meme_img = ImageTk.PhotoImage(im)
            self.meme_label.configure(image=self._meme_img, text="")

        lines = list(self.ctl.logs)[-200:]
        key = (len(self.ctl.logs), lines[-1] if lines else "")
        if key != getattr(self, "_log_key", None):
            self._log_key = key
            self._render_log(lines)
        self.root.after(250, self.tick)

    def _render_log(self, lines: list[str]) -> None:
        self.logbox.configure(state="normal")
        self.logbox.delete("1.0", "end")
        self.logbox.insert("end", "\n".join(lines))
        self.logbox.see("end")
        self.logbox.configure(state="disabled")

    def run(self) -> None:
        self.root.mainloop()
