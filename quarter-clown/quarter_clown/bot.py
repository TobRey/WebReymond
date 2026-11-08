"""Offizieller Discord-Bot „Quarter Clown“ (discord.py, Bot-Token, Slash-Commands).

Der Bot übernimmt Setup, Zustimmung, Status und Posting. Er tritt keinem
Voice-Channel bei und liest keine Nachrichteninhalte – er braucht nur die
Standard-Intents (Server + Voice-States), keine privilegierten Intents.

Zwei Betriebsarten mit identischen Commands:
- gehostet (Standard für Kunden): Der Bot läuft zentral auf dem Quarter-Clown-Server
  (`quarter_clown.server`) und leitet an die verbundene Windows-App weiter.
- eigener Bot (Entwickler): Bot und App laufen im selben Prozess (`LocalBackend`).
"""
from __future__ import annotations

import io
import logging
from typing import TYPE_CHECKING, Optional, Protocol

import discord
from discord import app_commands

from .config import invite_url
from .state import GuildConfig

if TYPE_CHECKING:  # pragma: no cover
    from .session import Controller

log = logging.getLogger(__name__)
LIME = 0xB6FF2E
PURPLE = 0xA23BFF
GREY = 0x2B2D31
ADMIN_ROLE_DEFAULT = "Quarter Clown Admin"


def is_authorized(member: discord.abc.User, admin_role: str = ADMIN_ROLE_DEFAULT) -> bool:
    if not isinstance(member, discord.Member):
        return False
    if member.guild_permissions.manage_guild or member.guild_permissions.administrator:
        return True
    return any(r.name == admin_role for r in member.roles)


def fmt_secs(sec: float) -> str:
    sec = max(0, int(round(sec)))
    return f"{sec // 60}:{sec % 60:02d}"


def consent_embed(title: str, participants: dict[int, str], consented: set[int], note: str = "") -> discord.Embed:
    lines = [f"{'✅' if pid in consented else '⏳'} <@{pid}>" for pid in participants] or ["Niemand im Voice-Channel."]
    desc = (
        "Diese Session wird **lokal aufgenommen** (sichtbare Webcam-Ansicht im Discord-Fenster + Discord-Ton) "
        "und **automatisch zu Memes verarbeitet**. Alle 15 Minuten erscheint hier genau ein Meme.\n\n"
        "• Es wird keine komplette Aufnahme gespeichert; verworfene Momente werden nach jedem Block gelöscht.\n"
        "• Für Captions gehen pro Block nur wenige ausgewählte Frames an die Claude-API (falls aktiviert).\n"
        "• Gestartet wird erst, wenn **alle** im Call zugestimmt haben. Neue Leute pausieren die Aufnahme bis zur Zustimmung.\n"
        "• **⛔ Sofort stoppen** beendet die Aufnahme sofort – für alle im Call."
    )
    if note:
        desc = f"**{note}**\n\n" + desc
    e = discord.Embed(title=title, description=desc, color=LIME)
    e.add_field(name=f"Zustimmung ({len(consented & set(participants))}/{len(participants)})", value="\n".join(lines), inline=False)
    return e


def status_embed(st: dict | None) -> discord.Embed:
    embed = discord.Embed(title="🤡⏰ Quarter Clown – Status", color=PURPLE)
    if st is None:
        embed.description = "💤 Die Quarter-Clown-App ist gerade nicht verbunden. Starte sie auf dem PC."
        return embed
    embed.add_field(name="Aufnahme", value=st["phase_label"], inline=False)
    if st.get("countdown") is not None:
        embed.add_field(name="Nächster Drop", value=f"in {fmt_secs(st['countdown'])} (#{st['next_block']})")
    embed.add_field(name="Zielkanal", value=st.get("target") or "nicht eingerichtet")
    if st.get("participants"):
        embed.add_field(name="Zustimmung", value="\n".join(
            f"{'✅' if p['ok'] else '⏳'} {p['name']}" for p in st["participants"]), inline=False)
    if st.get("test_label"):
        embed.set_footer(text=st["test_label"])
    return embed


class Backend(Protocol):
    """Was hinter den Commands und Buttons steckt (lokale App oder Server-Relay)."""

    admin_role: str
    hosted: bool

    def guild_config(self, guild_id: int) -> GuildConfig | None: ...
    async def save_setup(self, cfg: GuildConfig) -> str: ...
    async def new_pair_code(self, guild_id: int) -> str: ...
    async def status(self, guild_id: int) -> dict | None: ...
    async def session_active(self, guild_id: int) -> bool: ...
    async def stop(self, guild_id: int, reason: str) -> str: ...
    async def request_test(self, guild_id: int, **kw) -> str: ...
    async def consent(self, guild_id: int, user_id: int, name: str) -> str: ...
    async def decline(self, guild_id: int, user_id: int, name: str) -> str: ...
    async def roster(self, guild_id: int, joined: tuple | None, left: tuple | None) -> None: ...
    async def on_ready(self, bot: "QuarterClownBot") -> None: ...


class ConsentView(discord.ui.View):
    """Persistente Buttons – funktionieren auch nach einem Neustart des Bots."""

    def __init__(self, bot: "QuarterClownBot"):
        super().__init__(timeout=None)
        self.bot = bot

    @discord.ui.button(label="Ich stimme zu", emoji="✅", style=discord.ButtonStyle.success, custom_id="qc:consent")
    async def consent(self, interaction: discord.Interaction, _button: discord.ui.Button):
        await interaction.response.defer(ephemeral=True, thinking=True)
        msg = await self.bot.backend.consent(interaction.guild_id, interaction.user.id, interaction.user.display_name)
        await interaction.followup.send(msg, ephemeral=True)

    @discord.ui.button(label="Nicht einverstanden", emoji="🙅", style=discord.ButtonStyle.secondary, custom_id="qc:decline")
    async def decline(self, interaction: discord.Interaction, _button: discord.ui.Button):
        await interaction.response.defer(ephemeral=True, thinking=True)
        msg = await self.bot.backend.decline(interaction.guild_id, interaction.user.id, interaction.user.display_name)
        await interaction.followup.send(msg, ephemeral=True)

    @discord.ui.button(label="Sofort stoppen", emoji="⛔", style=discord.ButtonStyle.danger, custom_id="qc:stop")
    async def stop_now(self, interaction: discord.Interaction, _button: discord.ui.Button):
        bot, gid = self.bot, interaction.guild_id
        cfg = bot.backend.guild_config(gid)
        members = bot.voice_members(gid, cfg.voice_channel_id if cfg else None)
        if interaction.user.id not in members and not is_authorized(interaction.user, bot.backend.admin_role):
            await interaction.response.send_message("Stoppen können alle im Call und berechtigte Mitglieder.", ephemeral=True)
            return
        await interaction.response.defer(ephemeral=True, thinking=True)
        msg = await bot.backend.stop(gid, f"Sofort-Stopp durch {interaction.user.display_name}")
        await interaction.followup.send(msg, ephemeral=True)


def _no_rights(backend: Backend) -> str:
    return f"Nur Mitglieder mit „Server verwalten“ oder der Rolle **{backend.admin_role}** dürfen das."


class QuarterGroup(app_commands.Group):
    def __init__(self, bot: "QuarterClownBot"):
        super().__init__(name="quarter", description="Quarter Clown – alle 15 Minuten genau ein Meme", guild_only=True)
        self.bot = bot

    @property
    def backend(self) -> Backend:
        return self.bot.backend

    @app_commands.command(name="setup", description="Voice-Channel und Meme-Kanal festlegen (nur Berechtigte)")
    @app_commands.describe(voice="Voice-Channel der Session", text="Textkanal für die Memes",
                           testkanal="Optional: Kanal nur für Testposts")
    async def setup(self, interaction: discord.Interaction, voice: discord.VoiceChannel,
                    text: discord.TextChannel, testkanal: Optional[discord.TextChannel] = None):
        if not is_authorized(interaction.user, self.backend.admin_role):
            await interaction.response.send_message(_no_rights(self.backend), ephemeral=True)
            return
        await interaction.response.defer(ephemeral=True, thinking=True)
        if await self.backend.session_active(interaction.guild_id):
            await interaction.followup.send("Während einer Session nicht änderbar. Erst `/quarter stop`.", ephemeral=True)
            return
        problems = []
        for ch in [text] + ([testkanal] if testkanal else []):
            perms = ch.permissions_for(ch.guild.me)
            missing = [n for n, ok in [("Kanal ansehen", perms.view_channel), ("Nachrichten senden", perms.send_messages),
                                       ("Dateien anhängen", perms.attach_files), ("Links einbetten", perms.embed_links),
                                       ("Verlauf lesen", perms.read_message_history)] if not ok]
            if missing:
                problems.append(f"#{ch.name}: {', '.join(missing)}")
        if problems:
            await interaction.followup.send("Mir fehlen Rechte:\n- " + "\n- ".join(problems), ephemeral=True)
            return
        cfg = GuildConfig(
            guild_id=interaction.guild.id, guild_name=interaction.guild.name,
            voice_channel_id=voice.id, voice_channel_name=voice.name,
            text_channel_id=text.id, text_channel_name=text.name,
            test_channel_id=testkanal.id if testkanal else None, test_channel_name=testkanal.name if testkanal else "",
        )
        extra = await self.backend.save_setup(cfg)
        embed = discord.Embed(title="🤡⏰ Quarter Clown ist eingerichtet", color=LIME)
        embed.add_field(name="Voice-Channel", value=voice.mention)
        embed.add_field(name="Meme-Kanal", value=text.mention)
        embed.add_field(name="Testkanal", value=testkanal.mention if testkanal else "—")
        if extra:
            embed.add_field(name="Nächster Schritt", value=extra, inline=False)
        embed.set_footer(text="Gestartet wird erst, wenn alle im Call zugestimmt haben.")
        await interaction.followup.send(embed=embed, ephemeral=True)

    @app_commands.command(name="verbinden", description="Neuen Code für die Windows-App erzeugen (nur Berechtigte)")
    async def verbinden(self, interaction: discord.Interaction):
        if not is_authorized(interaction.user, self.backend.admin_role):
            await interaction.response.send_message(_no_rights(self.backend), ephemeral=True)
            return
        if self.backend.guild_config(interaction.guild_id) is None:
            await interaction.response.send_message("Bitte zuerst `/quarter setup` ausführen.", ephemeral=True)
            return
        msg = await self.backend.new_pair_code(interaction.guild_id)
        await interaction.response.send_message(msg, ephemeral=True)

    @app_commands.command(name="status", description="Aufnahmestatus und Countdown bis zum nächsten Meme")
    async def status(self, interaction: discord.Interaction):
        await interaction.response.defer(ephemeral=True, thinking=True)
        st = await self.backend.status(interaction.guild_id)
        await interaction.followup.send(embed=status_embed(st), ephemeral=True)

    @app_commands.command(name="stop", description="Aufnahme sofort beenden (nur Berechtigte)")
    async def stop(self, interaction: discord.Interaction):
        if not is_authorized(interaction.user, self.backend.admin_role):
            await interaction.response.send_message(
                "Nur Berechtigte können per Command stoppen. Alle im Call können den ⛔-Button nutzen.", ephemeral=True)
            return
        await interaction.response.defer(ephemeral=True, thinking=True)
        msg = await self.backend.stop(interaction.guild_id, f"/quarter stop durch {interaction.user.display_name}")
        await interaction.followup.send(msg, ephemeral=True)

    @app_commands.command(name="testmodus", description="Testlauf im Schnelltakt (10 s / 30 s / 1 min) – nur Berechtigte")
    @app_commands.describe(takt="Wie oft ein Test-Meme kommt",
                           quelle="Beispielmaterial (sofort, ohne Call) oder dein echter Call (mit Zustimmung)",
                           anzahl="Wie viele Test-Drops, danach stoppt der Testlauf (1–10)",
                           kanal="Testkanal (Standard: eingerichteter Testkanal, sonst dieser Kanal)")
    @app_commands.choices(
        takt=[app_commands.Choice(name="10 Sekunden", value=10), app_commands.Choice(name="30 Sekunden", value=30),
              app_commands.Choice(name="1 Minute", value=60)],
        quelle=[app_commands.Choice(name="Beispielmaterial", value="beispiel"),
                app_commands.Choice(name="Live-Call (mit Zustimmung)", value="live")])
    async def testmodus(self, interaction: discord.Interaction, takt: Optional[app_commands.Choice[int]] = None,
                        quelle: Optional[app_commands.Choice[str]] = None,
                        anzahl: Optional[app_commands.Range[int, 1, 10]] = 5,
                        kanal: Optional[discord.TextChannel] = None):
        if not is_authorized(interaction.user, self.backend.admin_role):
            await interaction.response.send_message(_no_rights(self.backend), ephemeral=True)
            return
        cfg = self.backend.guild_config(interaction.guild_id)
        channel = kanal
        if channel is None and cfg and cfg.test_channel_id:
            channel = interaction.guild.get_channel(cfg.test_channel_id)
        if channel is None and isinstance(interaction.channel, discord.TextChannel):
            channel = interaction.channel   # Command im Kanal ausgeführt = Kanal explizit gewählt
        if not isinstance(channel, discord.TextChannel):
            await interaction.response.send_message("Bitte einen Textkanal als `kanal` angeben.", ephemeral=True)
            return
        perms = channel.permissions_for(channel.guild.me)
        if not (perms.view_channel and perms.send_messages and perms.attach_files):
            await interaction.response.send_message(f"Ich darf in {channel.mention} nicht posten (Senden + Dateien).", ephemeral=True)
            return
        live = quelle is not None and quelle.value == "live"
        if (live or self.backend.hosted) and cfg is None:
            await interaction.response.send_message("Bitte zuerst `/quarter setup` ausführen.", ephemeral=True)
            return
        await interaction.response.defer(ephemeral=True, thinking=True)
        msg = await self.backend.request_test(interaction.guild_id, interval=takt.value if takt else 60, sample=not live,
                                              max_drops=int(anzahl or 5), channel_id=channel.id, channel_name=channel.name)
        await interaction.followup.send(msg, ephemeral=True)


class QuarterClownBot(discord.Client):
    def __init__(self, backend: Backend, sync_guild_id: int | None = None):
        intents = discord.Intents.default()   # guilds + voice_states, keine privilegierten Intents
        intents.message_content = False
        super().__init__(intents=intents)
        self.backend = backend
        self.sync_guild_id = sync_guild_id
        self.tree = app_commands.CommandTree(self)
        self.tree.add_command(QuarterGroup(self))
        self._ready_once = False

    # ----------------------------------------------------------- lifecycle
    async def setup_hook(self) -> None:
        self.add_view(ConsentView(self))
        if self.sync_guild_id:
            guild = discord.Object(id=self.sync_guild_id)
            self.tree.copy_global_to(guild=guild)
            await self.tree.sync(guild=guild)
        else:
            await self.tree.sync()

    async def on_ready(self) -> None:
        log.info("Bot online als %s · Invite: %s", self.user, self.invite)
        if not self._ready_once:  # on_ready kommt nach jeder Wiederverbindung erneut
            self._ready_once = True
            await self.backend.on_ready(self)

    async def on_voice_state_update(self, member: discord.Member, before: discord.VoiceState, after: discord.VoiceState):
        cfg = self.backend.guild_config(member.guild.id)
        vc = cfg.voice_channel_id if cfg else None
        if member.bot or not vc:
            return
        was = before.channel is not None and before.channel.id == vc
        now = after.channel is not None and after.channel.id == vc
        if was == now:
            return
        await self.backend.roster(member.guild.id, (member.id, member.display_name) if now else None,
                                  (member.id, member.display_name) if was and not now else None)

    # ------------------------------------------------------------- ops
    @property
    def invite(self) -> str:
        return invite_url(self.application_id) if self.application_id else ""

    def voice_members(self, guild_id: int | None, voice_channel_id: int | None) -> dict[int, str]:
        guild = self.get_guild(guild_id) if guild_id else None
        ch = guild.get_channel(voice_channel_id) if guild and voice_channel_id else None
        if not isinstance(ch, (discord.VoiceChannel, discord.StageChannel)):
            return {}
        return {m.id: m.display_name for m in ch.members if not m.bot}

    async def _channel(self, channel_id: int) -> discord.abc.Messageable:
        ch = self.get_channel(channel_id)
        if ch is None:
            ch = await self.fetch_channel(channel_id)
        return ch  # type: ignore[return-value]

    async def send_meme(self, channel_id: int, png: bytes, filename: str, content: str) -> bool:
        ch = await self._channel(channel_id)
        await ch.send(content=content, file=discord.File(io.BytesIO(png), filename=filename),
                      allowed_mentions=discord.AllowedMentions.none())
        return True

    async def has_recent_file(self, channel_id: int, filename: str) -> bool:
        ch = await self._channel(channel_id)
        async for msg in ch.history(limit=20):  # type: ignore[attr-defined]
            if self.user and msg.author.id == self.user.id and any(a.filename == filename for a in msg.attachments):
                return True
        return False

    async def say(self, channel_id: int | None, text: str) -> int | None:
        if not channel_id:
            return None
        try:
            ch = await self._channel(channel_id)
            msg = await ch.send(content=text[:1900], allowed_mentions=discord.AllowedMentions(users=True, roles=False, everyone=False))
            return msg.id
        except discord.HTTPException as exc:
            log.warning("Discord-Nachricht fehlgeschlagen: %s", exc)
            return None

    async def post_consent(self, channel_id: int | None, title: str, participants: dict[int, str], consented: set[int],
                           note: str = "", mention: str = "") -> int | None:
        if not channel_id:
            return None
        try:
            ch = await self._channel(channel_id)
            msg = await ch.send(content=mention[:1900] or None, embed=consent_embed(title, participants, consented, note),
                                view=ConsentView(self),
                                allowed_mentions=discord.AllowedMentions(users=True, roles=False, everyone=False))
            return msg.id
        except discord.HTTPException as exc:
            log.warning("Zustimmungsanfrage fehlgeschlagen: %s", exc)
            return None

    async def update_consent(self, channel_id: int | None, message_id: int | None, title: str,
                             participants: dict[int, str], consented: set[int], note: str = "") -> None:
        if not channel_id or not message_id:
            return
        ch = await self._channel(channel_id)
        await ch.get_partial_message(message_id).edit(embed=consent_embed(title, participants, consented, note))  # type: ignore[attr-defined]


# ---------------------------------------------------------------- lokal
class LocalBackend:
    """Eigener Bot: Commands gehen direkt an den Controller im selben Prozess."""

    hosted = False

    def __init__(self, controller: "Controller", admin_role: str = ADMIN_ROLE_DEFAULT):
        self.ctl = controller
        self.admin_role = admin_role

    def guild_config(self, guild_id: int) -> GuildConfig | None:
        g = self.ctl.store.guild
        return g if g.guild_id == guild_id else None

    async def save_setup(self, cfg: GuildConfig) -> str:
        self.ctl.apply_guild_config(cfg)
        return ""

    async def new_pair_code(self, guild_id: int) -> str:
        return "Nicht nötig – dieser Bot läuft direkt in deiner App."

    async def status(self, guild_id: int) -> dict | None:
        return self.ctl.status()

    async def session_active(self, guild_id: int) -> bool:
        return self.ctl.session_active

    async def stop(self, guild_id: int, reason: str) -> str:
        if not self.ctl.session_active:
            return "Es läuft gerade keine Aufnahme."
        await self.ctl.stop(reason)
        return "⛔ Aufnahme gestoppt. Es werden keine weiteren Memes gepostet."

    async def request_test(self, guild_id: int, **kw) -> str:
        return await self.ctl.request_test(**kw)

    async def consent(self, guild_id: int, user_id: int, name: str) -> str:
        return self.ctl.on_consent(user_id, name)

    async def decline(self, guild_id: int, user_id: int, name: str) -> str:
        return await self.ctl.on_decline(user_id, name)

    async def roster(self, guild_id: int, joined, left) -> None:
        if self.guild_config(guild_id):
            await self.ctl.on_roster_change(joined=joined, left=left)

    async def on_ready(self, bot: QuarterClownBot) -> None:
        await self.ctl.on_bot_ready()


class LocalBotLink:
    """Schnittstelle, die der Controller nutzt – hier direkt auf den Bot im selben Prozess."""

    def __init__(self, bot: QuarterClownBot, controller: "Controller"):
        self.bot = bot
        self.ctl = controller

    def is_ready(self) -> bool:
        return self.bot.is_ready()

    @property
    def invite(self) -> str:
        return self.bot.invite

    @property
    def label(self) -> str:
        return "Bot: online (eigener Bot)" if self.bot.is_ready() else "Bot: verbinde …"

    def voice_members(self) -> dict[int, str]:
        g = self.ctl.store.guild
        return self.bot.voice_members(g.guild_id, g.voice_channel_id)

    async def say(self, channel_id, text):
        return await self.bot.say(channel_id, text)

    async def post_consent(self, *a, **kw):
        return await self.bot.post_consent(*a, **kw)

    async def update_consent(self, *a, **kw):
        return await self.bot.update_consent(*a, **kw)

    async def send_meme(self, channel_id, png, filename, content):
        return await self.bot.send_meme(channel_id, png, filename, content)

    async def has_recent_file(self, channel_id, filename):
        return await self.bot.has_recent_file(channel_id, filename)

    async def run(self, token: str) -> None:
        await self.bot.start(token)

    async def close(self) -> None:
        await self.bot.close()
