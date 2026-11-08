# Quarter Clown betreiben (einmalige Einrichtung)

Damit Kunden nur noch einen Link brauchen, betreibst **du** drei Dinge:

| Teil | Wofür | Kosten |
|---|---|---|
| **Discord-Bot** (eine Discord-Application) | der eine Bot, den alle Kunden einladen | gratis |
| **Server** mit Docker (VPS oder dein WebHeaven-Server) | Bot läuft 24/7, Apps verbinden sich, Startseite mit Download | z. B. 4–6 CHF/Monat für einen kleinen VPS |
| **Öffentliches GitHub-Repo** `TobRey/quarter-clown` | baut automatisch die `QuarterClown-Setup.exe` und stellt sie bereit | gratis |

Kundendaten auf dem Server: nur Discord-Server-ID, Kanal-IDs und -Namen sowie ein gehashter App-Schlüssel.
Keine Bilder, kein Ton – Memes werden nur durchgereicht.

---

## 1. Discord-Bot anlegen

1. <https://discord.com/developers/applications> → **New Application** → „Quarter Clown“.
   Icon: `quarter_clown/assets/logo.png`.
2. **Bot** → **Reset Token** → Token kopieren (kommt in die Server-`.env`).
   *Privileged Gateway Intents*: alle **aus** lassen.
3. **Bot** → **Public Bot** eingeschaltet lassen (sonst können Kunden ihn nicht einladen).
4. Optional für einen netten Invite: **Installation** → *Install Link* auf „Discord Provided Link“, Scopes
   `bot` + `applications.commands`, Berechtigungen: View Channels, Send Messages, Embed Links, Attach Files,
   Read Message History. Die Startseite erzeugt den Invite-Link aber auch selbst.

> Ab 100 Servern verlangt Discord eine Bot-Verifizierung (Developer Portal → App Verification). Der Bot braucht
> keine privilegierten Intents, die Verifizierung ist dann reine Formalität.

## 2. Server starten

Voraussetzungen: ein Linux-Server mit Docker, eine (Sub-)Domain, deren DNS-A-Record auf den Server zeigt,
Ports 80 und 443 offen.

```bash
git clone https://github.com/TobRey/quarter-clown.git
cd quarter-clown/deploy
cp .env.example .env
nano .env            # QC_DOMAIN, DISCORD_BOT_TOKEN, QC_PUBLIC_URL eintragen
docker compose up -d --build
docker compose logs -f quarterclown     # sollte "Bot online als Quarter Clown#…" zeigen
```

Caddy holt automatisch ein HTTPS-Zertifikat. Danach ist unter `https://deine-domain` die Startseite mit
„Bot einladen“ und „Für Windows herunterladen“ erreichbar. **Das ist der Link für deine Kunden.**

Prüfen: `https://deine-domain/health` → `{"ok": true, "bot": true, ...}`

Update: `git pull && docker compose up -d --build`. Daten (Kopplungen) liegen im Volume `qc-data`.

## 3. Setup.exe bauen und veröffentlichen

1. Im öffentlichen Repo `TobRey/quarter-clown`: **Settings → Secrets and variables → Actions → Variables →
   New repository variable**: `QC_SERVER_URL` = `https://deine-domain`.
   Diese Adresse wird fest in die App eingebaut – Kunden müssen nichts eintragen.
2. **Actions → Release → Run workflow** (Version z. B. `1.0.0`) – oder einen Tag pushen: `git tag v1.0.0 && git push --tags`.
3. Nach ca. 10 Minuten liegt unter **Releases** die `QuarterClown-Setup.exe`. Der Download-Button der
   Startseite zeigt automatisch immer auf die neueste Version
   (`…/releases/latest/download/QuarterClown-Setup.exe`).

### Windows-Warnung („Der Computer wurde durch Windows geschützt“)

Kommt bei jeder neuen, nicht signierten App. Kunden klicken *Weitere Informationen → Trotzdem ausführen*
(steht auch auf der Startseite). Wegbekommen nur mit Code-Signing, z. B. **Azure Trusted Signing**
(ca. 10 USD/Monat) – kann später im Release-Workflow ergänzt werden.

## Ablauf für Kunden (zur Kontrolle)

1. Startseite öffnen → **Bot einladen** → Server wählen → Autorisieren.
2. **Für Windows herunterladen** → Setup doppelklicken. Die App startet und zeigt den Assistenten.
3. In Discord `/quarter setup voice:… text:…` → der Bot zeigt einen Code → in der App eingeben → fertig.
4. Testen: `/quarter testmodus takt:10 Sekunden`.

## Sicherheit

- Der Bot-Token liegt nur auf dem Server, nie in der App.
- Jede App bekommt beim Koppeln einen eigenen zufälligen Schlüssel; der Server speichert nur dessen Hash.
  Neues Koppeln (`/quarter verbinden`) macht den alten Schlüssel ungültig.
- Kopplungscodes: 8 Zeichen, 30 Minuten gültig, einmalig; max. 10 Versuche pro IP und 10 Minuten.
- Eine App kann nur in die per `/quarter setup` bzw. `/quarter testmodus` freigegebenen Kanäle ihres eigenen
  Servers posten, nur PNG bis 8 MB, frühestens alle 8 Sekunden ein Meme, max. 30 Textnachrichten pro 10 Minuten,
  Erwähnungen nur von Nutzern (kein @everyone).
