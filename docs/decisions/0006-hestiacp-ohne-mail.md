# 0006 – HestiaCP als Hosting-Layer, installiert ohne Mail-Komponenten

**Status:** akzeptiert (Phase 0, August 2026)

## Kontext
Für Kundenwebsites braucht es Webserver, PHP, Linux-Benutzer, SSL, Datenbanken und Backups. Das
selbst zu bauen ist für einen Einsteiger fehleranfällig; eine cPanel-Lizenz kommt aus Kostengründen
nicht in Frage.

## Entscheidung
**HestiaCP 1.10** (GPLv3, kostenlos) als interner Infrastruktur-Layer, installiert **ohne**
Exim, Dovecot, ClamAV und SpamAssassin. Kundenpostfächer werden bei einem externen Anbieter
eingekauft (siehe COSTS.md).

Das Panel ist **niemals** öffentlich erreichbar – Zugriff nur über VPN.

## Begründung
- Kostenlos, etabliert, aktiv gepflegt; unterstützt Debian 13 / aktuelle Ubuntu-LTS.
- Bietet eine dokumentierte Kommandozeile (`v-add-user`, `v-add-domain`, `v-add-database`, …),
  die sich sauber über eine Allowlist automatisieren lässt – die Grundlage unseres
  Provisioning-Layers.
- **Ohne Mail** spart die Installation rund 1 GB RAM (v.a. ClamAV) – bei 4 GB entscheidend.
- **Ohne Mail** entfällt zugleich das grösste Betriebsrisiko: Spamversand über gehackte Kundenseiten,
  Blacklisting der Server-IP, Mail-Queue-Probleme, Reputationspflege.

## Alternativen
- **CloudPanel:** schlanker, aber weniger Automatisierungs-Kommandos.
- **Froxlor:** kleinere Community.
- **CyberPanel:** OpenLiteSpeed-spezifisch.
- **aaPanel:** offene Datenschutzfragen.
- **Ganz ohne Panel:** maximale Kontrolle, aber deutlich mehr Handarbeit und Fehlerpotenzial für
  einen Einsteiger.
- **cPanel:** kostenpflichtig – ausgeschlossen.

## Sicherheitshinweis
In HestiaCP 1.9.7 wurden neun Sicherheitslücken auf einmal geschlossen, 1.10 brachte eine
Sicherheitsüberarbeitung. Das ist kein Ausschlussgrund (aktive Pflege ist gut), verpflichtet uns aber
zu: Panel nur über VPN, zeitnahe Updates, Release-Notes verfolgen.

## Konsequenzen
- Kunden sehen HestiaCP nie; alle Funktionen laufen über die WebReymond-Oberfläche.
- Die Provisioning-Aufrufe müssen strikt validiert werden (siehe SECURITY.md).
- E-Mail hängt an einem externen Anbieter → `MailProvider`-Abstraktion, damit ein Wechsel möglich
  bleibt.

## Wiedervorlage
Wenn wir mehrere Server betreiben (dann Konfigurationsverwaltung statt Panel prüfen) oder wenn
E-Mail doch selbst gehostet werden soll (dann eigener Mailserver auf **separatem** Server).
