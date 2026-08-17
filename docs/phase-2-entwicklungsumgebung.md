# Phase 2 – Entwicklungsumgebung auf Windows 11 einrichten

Ziel: Am Ende läuft WebHeaven auf deinem eigenen PC. Du brauchst dafür **keinen Server** und es
entstehen **keine Kosten**. Rechne mit 45–90 Minuten, davon viel Wartezeit beim Herunterladen.

Jeder Schritt ist gleich aufgebaut: **Was · Warum · Befehl · Erfolgskontrolle.**
Wenn eine Erfolgskontrolle etwas anderes ausgibt als beschrieben: nicht weitermachen, sondern melden.
Wir reparieren es, bevor wir weitergehen.

---

## Schritt 1 – WSL2 mit Ubuntu

**Was:** WSL2 ist ein echtes Linux, das innerhalb von Windows läuft.

**Warum:** Unser Server läuft später mit Linux. Wenn du auf deinem PC dasselbe verwendest,
funktionieren dieselben Befehle – und du lernst nebenbei genau das, was auf dem Server zählt.

**So geht's:**

1. Klicke auf **Start**, tippe `PowerShell`.
2. Rechtsklick auf **Windows PowerShell** → **Als Administrator ausführen**.
3. Diesen Befehl eingeben und mit Enter bestätigen:

```powershell
wsl --install -d Ubuntu
```

4. **PC neu starten**, wenn Windows dazu auffordert.
5. Nach dem Neustart öffnet sich ein Ubuntu-Fenster und fragt nach Benutzername und Passwort.
   - Benutzername: klein geschrieben, ohne Leerzeichen (z.B. `tobias`)
   - Passwort: Beim Tippen erscheint **nichts** auf dem Bildschirm – das ist normal, kein Fehler.
   - Dieses Passwort brauchst du später für `sudo`. Merken oder im Passwortmanager speichern.

**Erfolgskontrolle:** Im Ubuntu-Fenster eingeben:

```bash
lsb_release -a
```

Erwartete Ausgabe: eine Zeile mit `Ubuntu` und einer Versionsnummer.

> **Wenn es nicht klappt:** Meldet Windows etwas zu „Virtualisierung", muss diese im BIOS/UEFI
> aktiviert werden (heisst je nach Hersteller *Intel VT-x*, *AMD-V* oder *SVM Mode*). Melde dich –
> ich gehe das mit dir durch.

**Ab jetzt gilt:** Alle folgenden Befehle werden im **Ubuntu-Fenster** eingegeben, nicht in
PowerShell. Ubuntu findest du danach jederzeit über Start → „Ubuntu".

---

## Schritt 2 – Ubuntu aktualisieren

**Was:** Vorhandene Programme im Linux auf den neuesten Stand bringen.

**Warum:** Ein frisch installiertes System hat oft veraltete Pakete mit bekannten Sicherheitslücken.

```bash
sudo apt update && sudo apt upgrade -y
```

`sudo` = „als Administrator ausführen"; es fragt nach dem Passwort aus Schritt 1.

**Erfolgskontrolle:** Am Ende steht sinngemäss `0 upgraded, 0 newly installed` oder eine Liste
aktualisierter Pakete – aber **keine** rote Fehlermeldung.

---

## Schritt 3 – Node.js 22 über nvm

**Was:** Node.js ist die Laufzeitumgebung, in der WebHeaven läuft. `nvm` verwaltet Node-Versionen.

**Warum:** Über `nvm` kannst du die Version wechseln, ohne etwas kaputtzumachen. Wichtig, weil
Projekte unterschiedliche Versionen brauchen und unsere Version im Repository festgelegt ist (`.nvmrc`).

```bash
curl -o- https://raw.githubusercontent.com/nvm-sh/nvm/v0.40.3/install.sh | bash
```

Danach das Fenster **einmal schliessen und neu öffnen** (sonst kennt Ubuntu den Befehl `nvm` noch nicht).

```bash
nvm install 22
nvm use 22
nvm alias default 22
```

**Erfolgskontrolle:**

```bash
node --version
```

Erwartet: eine Ausgabe, die mit `v22.` beginnt.

---

## Schritt 4 – pnpm aktivieren

**Was:** pnpm lädt und verwaltet die Programmbibliotheken, die WebHeaven benutzt.

**Warum:** pnpm ist schneller und sparsamer als npm und kann mehrere Programme in einem Repository
verwalten – genau unser Aufbau (Portal, API, gemeinsame Pakete).

```bash
corepack enable
corepack prepare pnpm@10.33.0 --activate
```

**Erfolgskontrolle:**

```bash
pnpm --version
```

Erwartet: `10.33.0`.

---

## Schritt 5 – Git einrichten

**Was:** Git verwaltet die Versionen unseres Codes.

**Warum:** Jede Änderung wird nachvollziehbar und lässt sich rückgängig machen. Ohne Git kein
sicheres Arbeiten – und kein Backup des Codes.

```bash
git --version
git config --global user.name "Dein Name"
git config --global user.email "deine@email.ch"
git config --global init.defaultBranch main
```

**Erfolgskontrolle:**

```bash
git config --global --list
```

Erwartet: Deine Angaben werden angezeigt.

---

## Schritt 6 – SSH-Schlüssel für GitHub

**Was:** Ein SSH-Schlüssel besteht aus zwei Dateien: einem **privaten** Schlüssel (bleibt für immer
auf deinem PC) und einem **öffentlichen** Schlüssel (darf jeder sehen).

**Warum:** Damit weist du dich gegenüber GitHub aus, ohne jedes Mal ein Passwort einzugeben – und
zwar sicherer als mit Passwort. Denselben Mechanismus benutzen wir später für den Server.

```bash
ssh-keygen -t ed25519 -C "deine@email.ch"
```

Drei Rückfragen kommen:

1. *Speicherort* → einfach **Enter** (Standard ist richtig).
2. *Passphrase* → ein zusätzliches Passwort für den Schlüssel. **Empfohlen**: vergeben. Es schützt
   den Schlüssel, falls jemand deinen PC in die Hände bekommt.
3. *Passphrase wiederholen* → dasselbe nochmal.

Öffentlichen Schlüssel anzeigen:

```bash
cat ~/.ssh/id_ed25519.pub
```

Die komplette Zeile (beginnt mit `ssh-ed25519`, endet mit deiner E-Mail) markieren und kopieren.

Dann im Browser:
**github.com** → oben rechts auf dein Bild → **Settings** → links **SSH and GPG keys** →
**New SSH key** → Title: `Windows-PC` → Key: einfügen → **Add SSH key**.

> **Wichtig:** Die Datei **ohne** `.pub` (`id_ed25519`) ist der private Schlüssel. Den gibst du
> niemals weiter, lädst ihn nirgends hoch und schickst ihn nie per E-Mail oder Chat.

**Erfolgskontrolle:**

```bash
ssh -T git@github.com
```

Beim ersten Mal fragt SSH `Are you sure you want to continue connecting?` → `yes` eintippen.
Erwartet: `Hi <deinname>! You've successfully authenticated...`

---

## Schritt 7 – Docker Desktop

**Was:** Docker startet fertige Programme in abgeschotteten Behältern („Containern"). Wir brauchen
es zunächst nur für die Datenbank PostgreSQL.

**Warum:** So musst du keine Datenbank von Hand installieren und konfigurieren – ein Befehl startet
sie, ein Befehl entfernt sie wieder spurlos.

1. Herunterladen: <https://www.docker.com/products/docker-desktop/> (Windows-Version, kostenlos für
   private Nutzung und kleine Firmen).
2. Installieren, PC neu starten.
3. Docker Desktop öffnen → **Settings** (Zahnrad) → **Resources** → **WSL Integration** →
   den Schalter bei **Ubuntu** einschalten → **Apply & restart**.

**Erfolgskontrolle:** Im Ubuntu-Fenster:

```bash
docker --version
docker run --rm hello-world
```

Erwartet: eine Versionsnummer und danach `Hello from Docker!`.

---

## Schritt 8 – VS Code

**Was:** Der Editor, in dem du den Code siehst und bearbeitest.

**Warum:** Er zeigt Fehler direkt an, versteht unsere Projektstruktur und arbeitet direkt in WSL.

1. Herunterladen: <https://code.visualstudio.com/> und installieren.
2. In VS Code links auf das Erweiterungen-Symbol → nach **WSL** suchen → installieren.
3. Ebenfalls installieren: **ESLint** und **Prettier**.

**Erfolgskontrolle:** Im Ubuntu-Fenster `code .` eingeben – VS Code öffnet sich und unten links
steht **WSL: Ubuntu**.

---

## Schritt 9 – Projekt holen und starten

**Wichtig:** Das Projekt gehört **in das Linux-Dateisystem**, nicht auf `/mnt/c/...`.
Auf dem Windows-Laufwerk ist der Zugriff um ein Vielfaches langsamer und Dateirechte funktionieren
nicht richtig.

```bash
mkdir -p ~/projekte
cd ~/projekte
git clone git@github.com:TobRey/WebHeaven.git
cd WebHeaven
pnpm install
```

Datenbank starten und Anwendung starten:

```bash
pnpm db:up
pnpm dev
```

**Erfolgskontrolle:** Browser öffnen und <http://localhost:3000> aufrufen.
Erwartet: die WebHeaven-Startseite auf Deutsch. Unter <http://localhost:3000/en> dieselbe Seite auf
Englisch. Und im Browser <http://localhost:3001/health> zeigt `{"status":"ok",...}`.

Beenden: im Ubuntu-Fenster **Strg + C**, danach `pnpm db:down`.

---

## Häufige Stolperfallen

| Symptom | Ursache | Lösung |
|---|---|---|
| `command not found: nvm` | Fenster nach der Installation nicht neu geöffnet | Ubuntu-Fenster schliessen und neu öffnen |
| `permission denied (publickey)` beim `git clone` | SSH-Schlüssel nicht bei GitHub hinterlegt | Schritt 6 wiederholen, `ssh -T git@github.com` muss funktionieren |
| `Cannot connect to the Docker daemon` | Docker Desktop läuft nicht oder WSL-Integration ist aus | Docker Desktop starten, Schritt 7 Punkt 3 prüfen |
| Port 3000 ist belegt | Ein anderes Programm nutzt den Port | `pnpm dev` beenden, anderes Programm schliessen |
| Alles ist extrem langsam | Projekt liegt unter `/mnt/c/...` | Projekt nach `~/projekte/` verschieben |
| Seltsame Zeichen `^M` in Dateien | Windows-Zeilenenden (CRLF) | `git config --global core.autocrlf input`, Datei neu auschecken |

---

## Was du am Ende hast

- Ein Linux auf deinem Windows-PC, das dem späteren Server ähnelt
- Node.js 22, pnpm, Git, Docker, VS Code
- Einen SSH-Schlüssel für GitHub (denselben Mechanismus nutzen wir später für den Server)
- WebHeaven lokal lauffähig, zweisprachig, mit Datenbank

**Danach geht es weiter mit Phase 4:** Authentifizierung – Registrierung, Login, Passwort-Reset
und Zwei-Faktor-Anmeldung für Administratoren.
