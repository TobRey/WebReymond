# Externe Einrichtungen und Freigaben

Diese Liste nennt exakt, was ausserhalb von SwipeGroove eingerichtet werden muss. **Nichts davon wurde automatisch erstellt** – es gibt keine mitgelieferten Schlüssel.

## 1. Spotify (erforderlich für alle Playlist-Funktionen)

**Developer-App anlegen** – https://developer.spotify.com/dashboard
1. *Create app*; Redirect-URI **exakt** wie vom Installer angezeigt eintragen: `https://<basis-url>/auth/spotify/callback`
2. Unter *APIs used* „Web API“ wählen.
3. **Client-ID** kopieren → Installer bzw. Adminbereich → *Integrationen*. Ein Client-Secret ist nicht nötig (SwipeGroove nutzt Authorization Code mit PKCE).

**Development Mode (Stand Oktober 2026, verifizierungsbedürftig gegen die offizielle Doku):**
- Der **App-Besitzer braucht ein aktives Spotify Premium-Abo**, sonst funktioniert die App nicht.
- Maximal **5 freigeschaltete Nutzer** (Dashboard → *User Management*, Spotify-E-Mail eintragen). Bis dahin ist SwipeGroove faktisch eine geschlossene Beta.
- Pro Entwicklerkonto nur ein Client-ID (für neue Apps).
- Die Entwicklungs-Quota ist begrenzt; bei Überschreitung antwortet Spotify mit 429 (SwipeGroove zeigt dies ehrlich an und wartet gemäss `Retry-After`).

**Öffentlicher Betrieb – Extended Quota Mode:**
- Antrag nur durch **Organisationen** mit Firmen-E-Mail für einen bereits gestarteten Dienst; Spotify nennt u. a. **250 000 monatlich aktive Nutzer** als Kriterium. Bearbeitung bis zu 6 Wochen.
- Für eine neue Plattform ist dieser Zugang **nicht automatisch erreichbar**. Das ist die zentrale externe Abhängigkeit des Projekts.

**Berechtigungen (Scopes), die SwipeGroove anfordert:**
`playlist-read-private`, `playlist-read-collaborative`, `playlist-modify-public`, `playlist-modify-private`, `ugc-image-upload`.
Nur wenn der Admin «Auf der Seite abspielen» aktiviert, zusätzlich `user-read-playback-state`, `user-modify-playback-state`, `streaming`, `user-read-email`, `user-read-private` (die letzten drei verlangt das Web Playback SDK). Nutzer bestätigen sie einmalig beim ersten Abspielen.

**Nutzungsbedingungen (Spotify Developer Terms/Policy), relevant für den Betrieb:**
- Keine Verwendung von Spotify-Inhalten für KI-/ML-Training oder als Input für KI-Modelle → SwipeGroove schickt keine Spotify-Daten an OpenAI.
- Kommerzielle Nutzung und Wiedergabe-Integration benötigen ggf. Spotifys schriftliche Zustimmung → **Wiedergabe ist standardmässig deaktiviert** und wird vom Admin bewusst eingeschaltet (Admin → Einstellungen → Wiedergabe). Das Web Playback SDK funktioniert nur mit Spotify Premium und nicht in mobilen Browsern.
- Refresh-Tokens laufen nach **6 Monaten** ab; Nutzer müssen sich dann erneut anmelden (wird in den Einstellungen angezeigt).

## 2. OpenAI (optional – KI-Cover)

- Konto unter https://platform.openai.com, API-Schlüssel erzeugen, Guthaben/Zahlungsmethode hinterlegen.
- Schlüssel im Installer oder Adminbereich → *Integrationen* eintragen.
- Standardmodell `gpt-image-1-mini`, Qualität `medium` (im Adminbereich änderbar). Modellverfügbarkeit und Preise vor dem Livebetrieb auf https://platform.openai.com/docs/pricing prüfen (siehe `WIRTSCHAFTLICHKEIT.md`).
- Ohne Schlüssel bleibt der Cover-Generator sichtbar, aber deaktiviert, mit klarem Hinweis.

**Anthropic/Claude:** bietet **keine Bildgenerierungs-API**; daher OpenAI.

## 3. Stripe (optional – Premium-Abo CHF 2.00/Monat)

1. Stripe-Konto (Schweiz) unter https://dashboard.stripe.com, Geschäftsangaben vervollständigen (für Live-Zahlungen Pflicht).
2. **Produkt + Preis** anlegen: wiederkehrend, monatlich, **CHF 2.00** → `price_…` kopieren.
3. **Webhook** anlegen (Developers → Webhooks → *Add endpoint*): URL wie vom Installer angezeigt `https://<basis-url>/webhooks/stripe`; Ereignisse:
   `checkout.session.completed`, `customer.subscription.created`, `customer.subscription.updated`, `customer.subscription.deleted`, `invoice.paid`, `invoice.payment_failed`
   → **Signing Secret** (`whsec_…`) kopieren.
4. **Customer Portal** aktivieren (Settings → Billing → Customer portal): Kündigung zum Periodenende erlauben.
5. Secret Key (`sk_test_…`/`sk_live_…`), Publishable Key, Price-ID und Webhook-Secret in Installer/Adminbereich eintragen; **Modus test/live** passend wählen. Test- und Live-Ereignisse werden getrennt verarbeitet.
6. Stripe-Minimum für CHF ist 0.50 – CHF 2.00 ist zulässig.

Bezahlte Abos erst aktivieren (Live-Modus), wenn Geschäftsangaben, AGB/Datenschutz und Betreiberangaben vorhanden sind.

## 4. Hosting

- HTTPS-Zertifikat (AutoSSL), PHP ≥ 8.2, Cronjob (siehe Installationsanleitung).
- Betreiberangaben im Adminbereich → *Einstellungen* ausfüllen (erscheinen auf den rechtlichen Seiten; fehlende Angaben sind dort als auszufüllend markiert). Die rechtlichen Texte sind Strukturvorlagen, keine geprüften Rechtstexte.
