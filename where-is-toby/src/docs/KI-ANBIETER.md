# Kostenlose KI-Anbieter einrichten

Die NPC-Gespraeche laufen entweder ueber das **regelbasierte Offline-System** (kein Schluessel
noetig, Fall vollstaendig spielbar) oder ueber einen **KI-Anbieter**. Der Anbieter ist frei
konfigurierbar: Es ist kein bestimmtes Modell fest vorausgesetzt, weil sich kostenlose
Kontingente und Modellnamen regelmaessig aendern.

Alle Aufrufe laufen **serverseitig**. Der API-Schluessel wird verschluesselt gespeichert
(libsodium, alternativ OpenSSL AES-256-GCM) und niemals an den Browser gesendet.

---

## 1. Adminbereich → KI

Dort gibt es vier Vorlagen, die Basis-URL und Modellname vorbefuellen:

| Vorlage | Adapter | Basis-URL | Beispielmodell |
|---|---|---|---|
| Google Gemini | `gemini` | `https://generativelanguage.googleapis.com/v1beta` | `gemini-2.0-flash` |
| OpenRouter | `openai_compatible` | `https://openrouter.ai/api/v1` | Modelle mit Endung `:free` |
| Groq | `openai_compatible` | `https://api.groq.com/openai/v1` | `llama-3.3-70b-versatile` |
| Lokal (Ollama, LM Studio) | `openai_compatible` | `http://localhost:11434/v1` | `llama3.1` |
| Offline | – | – | – |

Nach dem Eintragen: **Verbindung testen**. Der Test sendet eine Minimalanfrage und zeigt die
Antwort oder eine verstaendliche Fehlermeldung (z. B. "Modell nicht gefunden (404)").

---

## 2. Google Gemini (kostenloses Kontingent)

1. Auf `aistudio.google.com/apikey` einen API-Schluessel erstellen (Google-Konto genuegt).
2. Adminbereich → KI → Vorlage **Google Gemini** uebernehmen.
3. Schluessel einfuegen, Modellnamen pruefen, **Speichern**, dann **Verbindung testen**.

Hinweis: Das kostenlose Kontingent ist begrenzt (Anfragen pro Minute und Tag). Die Limits in
der Verwaltung ("Anfragen pro Minute/Stunde") schuetzen davor, das Kontingent in einer Sitzung
zu verbrauchen. Standard: 12 Anfragen/Minute, 180/Stunde.

---

## 3. OpenRouter (Modelle mit `:free`)

1. Konto auf `openrouter.ai` anlegen, unter **Keys** einen Schluessel erzeugen.
2. Vorlage **OpenRouter** uebernehmen.
3. Unter `openrouter.ai/models` ein Modell mit der Endung `:free` auswaehlen und den
   vollstaendigen Namen eintragen, z. B. `meta-llama/llama-3.3-70b-instruct:free`.

Kostenlose Modelle werden bei OpenRouter regelmaessig ausgetauscht. Wenn ein Modell nicht mehr
verfuegbar ist, meldet der Verbindungstest "Modell oder Basis-URL nicht gefunden (404)" -
dann einfach ein anderes `:free`-Modell eintragen.

---

## 4. Groq (kostenloses Kontingent, sehr schnell)

1. Konto auf `console.groq.com`, dort **API Keys** → neuen Schluessel erzeugen.
2. Vorlage **Groq** uebernehmen und Modellnamen aus der Konsole eintragen.

---

## 5. Lokaler Server (Ollama, LM Studio, llama.cpp)

Nur sinnvoll, wenn der Webserver den Dienst erreichen kann (z. B. eigener Server, nicht
Shared Hosting). Basis-URL auf den lokalen Endpunkt setzen, Schluesselfeld leer lassen.

---

## 6. Weitere Einstellungen

| Feld | Bedeutung |
|---|---|
| Timeout | Sekunden, die auf eine Antwort gewartet wird (Standard 30) |
| Wiederholungen | Erneute Versuche bei Netzfehlern, 429 und 5xx (mit Wartezeit) |
| Maximale Antwortlaenge | Tokens pro Antwort (Standard 420 - kurze Chatantworten) |
| Temperatur | Streuung der Antworten (Standard 0.85) |
| Anfragen pro Minute/Stunde | eigenes Limit, schuetzt das kostenlose Kontingent |
| Rueckfall auf Offline | bei Ausfall antwortet das regelbasierte System weiter (empfohlen) |

---

## 7. Was passiert bei einem Ausfall?

* Der Fehler wird protokolliert (ohne Schluessel).
* Die Figur antwortet weiter - ueber das regelbasierte Dialogsystem.
* Der Spieler sieht **keine technische Fehlermeldung**, hoechstens einen Hinweis wie
  "Verbindung instabil - die Leitung rauscht kurz."
* Der Fall bleibt loesbar.

---

## 8. Kosten

Der Betrieb ist ohne Kosten moeglich: Offline-Modus, kostenlose Kontingente der Anbieter oder
ein lokales Modell. Es gibt keine kostenpflichtigen Bibliotheken und keinen zwingend
notwendigen externen Dienst.
