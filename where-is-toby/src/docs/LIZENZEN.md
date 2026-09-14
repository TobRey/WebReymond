# Verwendete Bibliotheken und Lizenzen

## 1. Programmcode

Der gesamte Anwendungscode (PHP, JavaScript, CSS) wurde fuer dieses Projekt geschrieben.
Es werden **keine** externen PHP-Bibliotheken, keine JavaScript-Frameworks und keine
CSS-Frameworks eingesetzt. Es gibt keinen Composer- oder npm-Schritt.

| Bestandteil | Herkunft | Lizenz |
|---|---|---|
| PHP-Anwendung (`app/`, `index.php`, `install.php`) | eigene Entwicklung | Projektlizenz |
| JavaScript (`assets/js/`, ES-Module, ohne Build) | eigene Entwicklung | Projektlizenz |
| CSS (`assets/css/`) | eigene Entwicklung | Projektlizenz |
| Bilder (`assets/img/`, SVG) | prozedural erzeugt (`tools/build_*.php`) | Projektlizenz |
| Audio (WAV) | zur Laufzeit in PHP berechnet (`App\Service\AudioSynth`) | Projektlizenz |

## 2. Schriftarten

| Schrift | Verwendung | Lizenz |
|---|---|---|
| DejaVu Sans / DejaVu Sans Bold / DejaVu Sans Mono (`assets/fonts/`) | nur serverseitig fuer die Bildgenerierung mit PHP-GD (Vermisstenplakat, Aktenkarte) | Bitstream Vera License / DejaVu Changes: Public Domain - freie Weitergabe erlaubt |
| Systemschriften (Inter, Segoe UI, system-ui, Helvetica, Arial, ui-monospace) | Weboberflaeche | keine Weitergabe, es werden nur lokal vorhandene Schriften verwendet |

Die vollstaendige DejaVu-Lizenz liegt unter `assets/fonts/LICENSE.txt`.

## 3. Browser-Schnittstellen

| Schnittstelle | Zweck | Hinweis |
|---|---|---|
| Web Audio API | Wellenform, Rueckwaerts- und Tempowiedergabe | Standard, keine Bibliothek |
| Web Speech API (`SpeechRecognition`) | optionale Spracheingabe im Chat | nur wenn vom Browser unterstuetzt |
| SpeechSynthesis | Vorlesefunktion fuer Transkripte | optional |
| Fetch, ES-Module, CSS Grid | Grundlagen der Oberflaeche | ohne Polyfills |

## 4. Optionale externe Dienste

| Dienst | Zweck | Pflicht? |
|---|---|---|
| Google Gemini API | KI-Dialoge | nein - Offline-Modus ist vollwertig |
| OpenAI-kompatible Anbieter (OpenRouter, Groq, lokale Server) | KI-Dialoge | nein |

Es werden keine Tracker, keine CDN-Ressourcen, keine Werbenetzwerke und keine externen
Schriften geladen.

## 5. Inhalte

Alle Texte, Figuren, Orte, Ereignisse, Zeitungsausschnitte, Dokumente und Fallinhalte sind
frei erfunden. Aehnlichkeiten mit realen Personen, Orten oder Ereignissen sind
unbeabsichtigt. Das Kuerzel "FBI" wird ausschliesslich als Element einer fiktiven Geschichte
verwendet; es besteht keine Verbindung zu einer realen Behoerde.
