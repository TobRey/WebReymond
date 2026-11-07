"""Offizieller Discord-Bot „Quarter Clown“ (discord.py, Bot-Token, Slash-Commands).

Der Bot übernimmt Setup, Zustimmung, Status und Posting. Er tritt keinem
Voice-Channel bei und liest keine Nachrichteninhalte – er braucht nur die
Standard-Intents (Server + Voice-States), keine privilegierten Intents.
"""
from __future__ import annotations

import io
import logging
from typing import TYPE_CHECKING, Optional

import discord
from discord import app_commands

from .config import Settings, invite_url
from .state import StateStore

if TYPE_CHECKING:  # pragma: no cover
    from .session import Controller

log = logging.getLogger(__name__)
LIME = 0xB6FF2E
PURPLE = 0xA23BFF
GREY = 0x2B2D31


def is_authorized(member: discord.abc.User, admin_role: str) -> bool:
    if not isinstance(member, discord.Member):
        return False
    if member.guild_permissions.manage_guild or member.guild_permissions.administrator:
        return True
    return any(r.name == admin_role for r in member.roles)


def fmt_secs(sec: float) -> str:
    sec = max(0, int(round(sec)))
    return f"{sec // 60}:{sec % 60:02d}"


class ConsentView(discord.ui.View):
    """Persistente Buttons – funktionieren auch nach einem Neustart des Bots."""

    def __init__(self, bot: "QuarterClownBot"):
        super().__init__(timeout=None)
        self.bot = bot

    @discord.ui.button(label="Ich stimme zu", emoji="✅", style=discord.ButtonStyle.success, custom_id="qc:consent")
    async def consent(self, interaction: discord.Interaction, _button: discord.ui.Button):
        msg = self.bot.controller.on_consent(interaction.user.id, interaction.user.display_name)
        await interaction.response.send_message(msg, ephemeral=True)

    @discord.ui.button(label="Nicht einverstanden", emoji="🙅", style=discord.ButtonStyle.secondary, custom_id="qc:decline")
    async def decline(self, interaction: discord.Interaction, _button: discord.ui.Button):
        msg = await self.bot.controller.on_decline(interaction.user.id, interaction.user.display_name)
        await interaction.response.send_message(msg, ephemeral=True)

    @discord.ui.button(label="Sofort stoppen", emoji="⛔", style=discord.ButtonStyle.danger, custom_id="qc:stop")
    async def stop_now(self, interaction: discord.Interaction, _button: discord.ui.Button):
        ctl = self.bot.controller
        member_ids = set(self.bot.voice_members())
        if interaction.user.id not in member_ids and not is_authorized(interaction.user, self.bot.settings.admin_role):
            await interaction.response.send_message("Stoppen können alle im Call und berechtigte Mitglieder.", ephemeral=True)
            return
        if not ctl.session_active:
            await interaction.response.send_message("Es läuft gerade keine Aufnahme.", ephemeral=True)
            return
        await interaction.response.send_message("⛔ Aufnahme wird sofort gestoppt.", ephemeral=True)
        await ctl.stop(f"Sofort-Stopp durch {interaction.user.display_name}")


class QuarterGroup(app_commands.Group):
    def __init__(self, bot: "QuarterClownBot"):
        super().__init__(name="quarter", description="Quarter Clown – alle 15 Minuten genau ein Meme", guild_only=True)
        self.bot = bot

    @app_commands.command(name="setup", description="Voice-Channel und Meme-Kanal festlegen (nur Berechtigte)")
    @app_commands.describe(voice="Voice-Channel der Session", text="Textkanal für die Memes",
                           testkanal="Optional: Kanal nur für Testposts (Testmodus)")
    async def setup(self, interaction: discord.Interaction, voice: discord.VoiceChannel,
                    text: discord.TextChannel, testkanal: Optional[discord.TextChannel] = None):
        bot = self.bot
        if not is_authorized(interaction.user, bot.settings.admin_role):
            await interaction.response.send_message(
                f"Nur Mitglieder mit „Server verwalten“ oder der Rolle **{bot.settings.admin_role}** dürfen das.", ephemeral=True)
            return
        if bot.controller.session_active:
            await interaction.response.send_message("Während einer Session nicht änderbar. Erst `/quarter stop`.", ephemeral=True)
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
            await interaction.response.send_message("Mir fehlen Rechte:\n- " + "\n- ".join(problems), ephemeral=True)
            return
        g = bot.store.guild
        g.guild_id, g.guild_name = interaction.guild.id, interaction.guild.name
        g.voice_channel_id, g.voice_channel_name = voice.id, voice.name
        g.text_channel_id, g.text_channel_name = text.id, text.name
        g.test_channel_id = testkanal.id if testkanal else None
        g.test_channel_name = testkanal.name if testkanal else ""
        bot.store.save()
        bot.controller.log(f"Setup: Voice {voice.name}, Memes in #{text.name}" + (f", Test in #{testkanal.name}" if testkanal else ""))
        embed = discord.Embed(title="🤡⏰ Quarter Clown ist eingerichtet", color=LIME)
        embed.add_field(name="Voice-Channel", value=voice.mention)
        embed.add_field(name="Meme-Kanal", value=text.mention)
        embed.add_field(name="Testkanal", value=testkanal.mention if testkanal else "—")
        embed.set_footer(text="Start/Stop in der Windows-App. Gestartet wird erst, wenn alle im Call zugestimmt haben.")
        await interaction.response.send_message(embed=embed, ephemeral=True)

    @app_commands.command(name="status", description="Aufnahmestatus und Countdown bis zum nächsten Meme")
    async def status(self, interaction: discord.Interaction):
        st = self.bot.controller.status()
        embed = discord.Embed(title="🤡⏰ Quarter Clown – Status", color=PURPLE)
        embed.add_field(name="Aufnahme", value=st["phase_label"], inline=False)
        if st["countdown"] is not None:
            embed.add_field(name="Nächster Drop", value=f"in {fmt_secs(st['countdown'])} (#{st['next_block']})")
        embed.add_field(name="Zielkanal", value=st["target"] or "nicht eingerichtet")
        if st["participants"]:
            embed.add_field(name="Zustimmung", value="\n".join(
                f"{'✅' if p['ok'] else '⏳'} {p['name']}" for p in st["participants"]), inline=False)
        if st["test_mode"]:
            embed.set_footer(text="TESTMODUS · 30-Sekunden-Takt")
        await interaction.response.send_message(embed=embed, ephemeral=True)

    @app_commands.command(name="stop", description="Aufnahme sofort beenden (nur Berechtigte)")
    async def stop(self, interaction: discord.Interaction):
        if not is_authorized(interaction.user, self.bot.settings.admin_role):
            await interaction.response.send_message(
                "Nur Berechtigte können per Command stoppen. Alle im Call können den ⛔-Button nutzen.", ephemeral=True)
            return
        if not self.bot.controller.session_active:
            await interaction.response.send_message("Es läuft gerade keine Aufnahme.", ephemeral=True)
            return
        await interaction.response.send_message("⛔ Aufnahme gestoppt. Es werden keine weiteren Memes gepostet.", ephemeral=True)
        await self.bot.controller.stop(f"/quarter stop durch {interaction.user.display_name}")


class QuarterClownBot(discord.Client):
    def __init__(self, controller: "Controller", settings: Settings, store: StateStore):
        intents = discord.Intents.default()   # guilds + voice_states, keine privilegierten Intents
        intents.message_content = False
        super().__init__(intents=intents)
        self.controller = controller
        self.settings = settings
        self.store = store
        self.tree = app_commands.CommandTree(self)
        self.tree.add_command(QuarterGroup(self))
        self._ready_once = False

    # ----------------------------------------------------------- lifecycle
    async def setup_hook(self) -> None:
        self.add_view(ConsentView(self))
        if self.settings.discord_guild_id:
            guild = discord.Object(id=self.settings.discord_guild_id)
            self.tree.copy_global_to(guild=guild)
            await self.tree.sync(guild=guild)
        else:
            await self.tree.sync()

    async def on_ready(self) -> None:
        self.controller.log(f"Bot online als {self.user} · Invite: {invite_url(self.application_id)}")
        if not self._ready_once:  # on_ready kommt nach jeder Wiederverbindung erneut
            self._ready_once = True
            await self.controller.on_bot_ready()

    async def on_resumed(self) -> None:
        self.controller.log("Discord-Verbindung wiederhergestellt")

    async def on_voice_state_update(self, member: discord.Member, before: discord.VoiceState, after: discord.VoiceState):
        vc = self.store.guild.voice_channel_id
        if member.bot or not vc:
            return
        was = before.channel is not None and before.channel.id == vc
        now = after.channel is not None and after.channel.id == vc
        if was == now:
            return
        await self.controller.on_roster_change(joined=(member.id, member.display_name) if now else None,
                                               left=(member.id, member.display_name) if was and not now else None)

    # ------------------------------------------------------------- helpers
    @property
    def invite(self) -> str:
        return invite_url(self.application_id) if self.application_id else ""

    def voice_members(self) -> dict[int, str]:
        g = self.store.guild
        guild = self.get_guild(g.guild_id) if g.guild_id else None
        ch = guild.get_channel(g.voice_channel_id) if guild and g.voice_channel_id else None
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
        await ch.send(content=content, file=discord.File(io.BytesIO(png), filename=filename))
        return True

    async def has_recent_file(self, channel_id: int, filename: str) -> bool:
        ch = await self._channel(channel_id)
        async for msg in ch.history(limit=20):  # type: ignore[attr-defined]
            if self.user and msg.author.id == self.user.id and any(a.filename == filename for a in msg.attachments):
                return True
        return False

    async def say(self, channel_id: int | None, text: str = "", embed: discord.Embed | None = None,
                  view: discord.ui.View | None = None) -> discord.Message | None:
        if not channel_id:
            return None
        try:
            ch = await self._channel(channel_id)
            kwargs = {"content": text or None, "embed": embed}
            if view is not None:
                kwargs["view"] = view
            return await ch.send(**kwargs, allowed_mentions=discord.AllowedMentions(users=True, roles=False, everyone=False))
        except discord.HTTPException as exc:
            self.controller.log(f"Discord-Nachricht fehlgeschlagen: {exc}")
            return None

    def consent_embed(self, title: str, participants: dict[int, str], consented: set[int], note: str = "") -> discord.Embed:
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
