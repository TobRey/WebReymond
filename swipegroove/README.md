# SwipeGroove

Spotify-Playlist-Manager mit Community und Swipe-Feed. PHP 8.2+ (empfohlen 8.4), MySQL/MariaDB, Preact-Frontend (vorgebaut). Für GoDaddy cPanel (Domain-Root oder Unterordner).

- **Installation:** `docs/INSTALLATION-GODADDY.md`
- **Externe Freigaben (Spotify, OpenAI, Stripe):** `docs/EXTERNE-FREIGABEN.md`
- **Getestete Funktionen & Einschränkungen:** `docs/FUNKTIONEN-UND-EINSCHRAENKUNGEN.md`
- **Wirtschaftlichkeit KI-Cover:** `docs/WIRTSCHAFTLICHKEIT.md`
- **Aufbewahrung/Bereinigung:** `docs/DATENSCHUTZ-TECHNIK.md`
- **Updates:** `docs/UPDATES.md`

## Struktur

```
index.php            Front-Controller (alle Routen)
.htaccess            Rewrite + Schutz der internen Ordner
app/src/             PHP-Quellcode (Namespace SwipeGroove\, PSR-4, eigener Autoloader)
  Core/              Konfiguration, Router, DB, Sessions, Krypto, I18n, Rate-Limits, Migrator
  Auth/              Sessions, Registrierung/Besitzer-Logik
  Spotify/           OAuth (PKCE), Tokenspeicher, API-Client (Feb-2026-Endpunkte), Playlist-Dienste, Feed, Swipe
  Community/         Follows, Freunde, Likes, Kommentare, Feed, Meldungen, Benachrichtigungen
  Messaging/         Chats mit Anfragen und Polling
  Covers/            Bildanbieter-Schnittstelle, OpenAI-Provider, Kontingente
  Billing/           Stripe-Client, Abo-Logik, Webhooks
  Admin/             Audit-Log, Fehlerlog, Cron
  Http/              Routen, Controller, Installer, SPA-Shell
migrations/          Versionsbasierte DB-Migrationen (laufen automatisch)
lang/                Server-Übersetzungen (de/en)
assets/              Gebautes Frontend (Vite-Manifest) + Logo/Favicon
frontend/            Frontend-Quellcode (Preact + TypeScript); Build: `npm install && npm run build`
cron/run.php         Cron-Einstieg (CLI) – alternativ `/cron/run?key=…`
config/              `config.php` wird vom Installer erzeugt (Secrets, nicht web-zugänglich)
storage/             Logs, Cache, KI-Cover (nicht web-zugänglich)
uploads/             Profilbilder (Skriptausführung deaktiviert)
tests/               Backend-Tests (`php tests/run.php`) und Browsertests (`tests/e2e/`)
```

## Entwicklung

```bash
# Backend-Tests (MariaDB-Testdatenbank swipegroove_test, Nutzer sg/sgpass oder ENV SG_TEST_DB_*)
php tests/run.php

# Frontend bauen (Node 22)
cd frontend && npm install && npm run build

# Lokaler Betrieb im Unterordner /groove mit Fake-Spotify (nur Entwicklung)
php -S 127.0.0.1:8080 -t <docroot-mit-symlink-groove> tests/e2e/dev-router.php
php -S 127.0.0.1:8090 tests/e2e/fake-spotify-server.php
```

Für den Fake-Spotify-Betrieb werden in `config/config.php` die Schlüssel `spotify.accounts_base_url` und `spotify.api_base_url` gesetzt; in Produktion fehlen sie (Standard: echte Spotify-Endpunkte).
