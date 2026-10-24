# Live-Auflegen aus rekordbox (Windows) – Audio-Routing

BlockRave nimmt **nur den fertigen Audio-Mix** auf – keine Jogwheels, Regler, MIDI-Daten oder
Deck-Anzeigen. Die Aufnahme läuft komplett in der Mod (WASAPI über JNA), ein separat zu startendes
Hilfsprogramm ist **nicht** nötig. Die Aufnahme startet ausschließlich, wenn **du selbst** im DJ-Pult
auf „● Live-Übertragung starten“ klickst; andere Spieler können dein Mikrofon oder deinen PC nicht
fernsteuern.

> **Ehrlicher Hinweis:** Es gibt keine offizielle rekordbox-Schnittstelle für den Audio-Mix. BlockRave
> greift deshalb den Klang über die **Windows-Audio-Engine** ab. Was rekordbox über **ASIO** oder im
> **Exklusivmodus** ausgibt, läuft an der Windows-Audio-Engine vorbei und kann so **nicht** erfasst
> werden (das ist eine Einschränkung von Windows, siehe unten). Ob dein Setup ohne Umstellung
> funktioniert, hängt also davon ab, wie rekordbox bei dir ausgibt.

## Was Windows offiziell kann (Microsoft-Dokumentation)

Quelle: Microsoft Learn „Loopback Recording“ (WASAPI) und „ActivateAudioInterfaceAsync“ /
„AUDIOCLIENT_PROCESS_LOOPBACK_PARAMS“:

* **Loopback** erfasst, was auf einem Wiedergabegerät abgespielt wird – *aber nur für Streams im
  Shared Mode*. Zitat: „A client can enable loopback mode only for a shared-mode stream … Exclusive-mode
  streams cannot operate in loopback mode.“
* **Prozess-Loopback** (nur die Ausgabe eines bestimmten Programms, oder alles *außer* einem Programm)
  gibt es laut Microsoft ab **Windows 10 Build 20438** (in der Praxis: Windows 11).
* **ASIO** ist ein Treiberweg des Geräteherstellers, der die Windows-Audio-Engine umgeht → kein Loopback.

## Die Quellen im DJ-Pult (Modus „Live“)

| Quelle | Was wird aufgenommen | Rückkopplung? |
|---|---|---|
| **Nur rekordbox (Prozess-Loopback)** – erscheint nur, wenn `rekordbox.exe` läuft | ausschließlich der Klang von rekordbox | keine |
| **Gesamter PC-Klang ohne Minecraft** | alle Programme außer Minecraft | keine |
| **Ausgang: <Gerät> – Loopback** | alles, was auf diesem Gerät läuft | Minecraft-Klänge werden mit aufgenommen, wenn Minecraft auf demselben Gerät ausgibt (Rave-Musik selbst wird beim Sender nicht abgespielt) |
| **Eingang: <Gerät>** | ein Aufnahmegerät (Line-In, Stereomix, virtuelles Kabel, Recording-Kanal eines Mixers) | – |

Ein **Mikrofon** wird nie automatisch gewählt. Eingänge erscheinen nur in der Liste und müssen bewusst
ausgewählt werden.

## Empfohlene Konfigurationen (kleinste nötige Anpassung zuerst)

### A) rekordbox gibt über ein Windows-Audiogerät aus (Laptop-Lautsprecher, Kopfhörer, USB-Interface im Windows-Modus)

**Keine Anpassung nötig.** Im DJ-Pult „Nur rekordbox (Prozess-Loopback)“ wählen (Windows 11) oder
„Ausgang: <dein Gerät> – Loopback“. Prüfe, dass sich die **Pegelanzeige** im DJ-Pult bewegt.

### B) rekordbox gibt über ein DJ-Controller-/Interface mit **ASIO** aus

Das ist bei vielen Controllern der Standard. Dann ist Loopback **nicht möglich**. Kleinste Anpassungen:

1. **Ausgabe in rekordbox auf Windows-Audio umstellen** (falls dein Controller einen WDM/WASAPI-Treiber
   anbietet): In rekordbox unter *Einstellungen → Audio* das Ausgabegerät so wählen, dass es **nicht**
   über ASIO läuft. Die genaue Bezeichnung hängt von rekordbox-Version und Treiber ab – bitte im
   rekordbox-Handbuch („Preferences → Audio“) nachsehen. Danach Quelle wie in A).
2. **Aufnahmekanal des Controllers/Mixers nutzen:** Viele Pioneer-DJ-Mixer/Controller stellen den
   Master-Mix zusätzlich als **Windows-Aufnahmegerät** bereit. Dann im DJ-Pult „Eingang: <Gerät>“ wählen.
   (Ob dein Modell das kann, steht in dessen Treiber-/Bedienungsanleitung.)
3. **Hardware-Weg:** Booth-/Rec-Ausgang des Controllers per Kabel in den **Line-In** des PCs und
   „Eingang: Line-In“ wählen.
4. **Virtuelles Audiokabel** (letzte Möglichkeit, zusätzliche Treiber-Software, kein separat zu
   startendes Programm): rekordbox auf das virtuelle Kabel ausgeben und dessen Aufnahmeseite als
   „Eingang“ wählen. Zum Mithören brauchst du dann eine Weiterleitung auf deine Kopfhörer.

### C) Exklusivmodus

Falls in der Windows-Soundsteuerung beim Gerät „Anwendungen haben alleinige Kontrolle“ aktiv ist und
rekordbox das Gerät exklusiv nutzt, liefert Loopback keinen Ton (BlockRave zeigt „Kein Signal!“). Dann
Exklusivmodus deaktivieren (Systemsteuerung → Sound → Gerät → Eigenschaften → Erweitert) oder Weg B
nutzen.

## Rückkopplung vermeiden

* Beim **sendenden Spieler** spielt BlockRave den eigenen Live-Stream standardmäßig **nicht** ab (die
  Option „Eigenen Live-Stream mithören“ ist aus). Du hörst rekordbox direkt.
* Bevorzugt **„Nur rekordbox“** oder **„Gesamter PC-Klang ohne Minecraft“** verwenden – dann werden auch
  andere Minecraft-Geräusche nicht übertragen.
* Wer „Mithören“ einschaltet und gleichzeitig „Ausgang … Loopback“ auf demselben Gerät nutzt, erzeugt
  eine Rückkopplung – deshalb ist das standardmäßig aus.

## Verzögerung

Für eine stabile, knackfreie Übertragung wird gepuffert. Die Live-Verzögerung beträgt standardmäßig
ca. **1,5 s (Server-Puffer, `liveDelayMs`) + Aufnahmepuffer + ca. 40 ms Analyse**. Die geschätzte
Verzögerung wird im DJ-Pult und im HUD angezeigt. Die Lichtshow richtet sich nach der **gehörten**
Wiedergabezeit, nicht nach dem Aufnahmezeitpunkt.

## Fehlersuche

| Anzeige | Ursache / Lösung |
|---|---|
| „Kein Signal!“ | rekordbox gibt über ASIO/Exklusivmodus aus, Musik pausiert, falsches Gerät → Abschnitt B/C |
| „… nicht unterstützt“ beim Prozess-Loopback | Windows-Version zu alt → „Ausgang … Loopback“ verwenden |
| „Gerät wird exklusiv … verwendet“ | Exklusivmodus deaktivieren |
| „Zugriff verweigert“ bei Eingang | Windows-Datenschutz → Mikrofonzugriff für Desktop-Apps erlauben (betrifft alle Aufnahmegeräte) |
| Live-Aufnahme nur unter Windows | macOS/Linux werden für Live nicht unterstützt; MP3-Modus funktioniert überall |
