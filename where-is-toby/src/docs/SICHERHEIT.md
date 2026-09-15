# Sicherheit

## 1. Kurzfassung

| Bereich | Massnahme |
|---|---|
| Passwoerter | `password_hash` (bcrypt), automatisches Rehashing, nie Klartext |
| Sitzungen | eigener Speicherpfad, `HttpOnly`, `SameSite=Lax`, `Secure` bei HTTPS, Regeneration, Idle-Timeout 2 h, grober Fingerabdruck |
| CSRF | Token je Sitzung, Pflicht bei jedem POST (Formular oder `X-CSRF-Token`) |
| Brute Force | Kontosperre nach N Fehlversuchen, zusaetzliches IP-Limit |
| Rate-Limits | pro IP (API, Registrierung, Login) und pro Konto (Chat, Raetsel, Hinweise) |
| Ausgaben | konsequentes Escaping (`View::e`), JSON sicher eingebettet (`View::js`) |
| Eingaben | serverseitige Validierung, Laengenbegrenzung, Steuerzeichenfilter |
| Dateien | Zugriff nur ueber Whitelist-Namen, `realpath`-Pruefung gegen Path Traversal |
| Uploads | Endung + echter MIME-Typ, Groessenlimit, zufaellige Namen, Bildneuberechnung, SVG-Bereinigung, kein PHP unter `uploads/` |
| Geheimnisse | API-Schluessel verschluesselt (libsodium/OpenSSL), niemals im Browser |
| HTTP-Header | CSP, `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy`, HSTS bei HTTPS |
| Datenverzeichnisse | `.htaccess` mit `Require all denied`, zusaetzlich `index.php`-Wachposten, optional ausserhalb von `public_html` |
| Protokolle | sicherheitsrelevante Ereignisse in `security.log`, Geheimnisse werden ausgefiltert |

---

## 2. Content Security Policy

Gesetzt in `index.php`:

```
default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline';
img-src 'self' data: blob:; media-src 'self' data: blob:; font-src 'self';
connect-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self';
frame-ancestors 'self'
```

Es werden keine externen Skripte, Schriften oder Bilder geladen. `unsafe-inline` gilt nur fuer
Stile (Inline-Positionen von Karten und Bilddetails), nicht fuer Skripte.

---

## 3. Was bewusst nicht passiert

* **Keine echten Angriffsfunktionen.** Alle "Hacking"-Elemente sind Inhalte aus JSON-Dateien.
  Es gibt keinen Port-Scanner, keinen Passwortknacker, keine Netzwerkzugriffe ausser zum
  konfigurierten KI-Anbieter.
* **Keine API-Schluessel im Browser.** Der Chat-Endpunkt ruft den Anbieter serverseitig auf.
* **Keine Tracker.** Ein technisch notwendiges Sitzungs-Cookie, sonst nichts.
* **Keine unnoetigen Daten.** Benutzername, optionale E-Mail, Passwort-Hash, Spielstand.
  Konten koennen im Bereich "Konto" vollstaendig geloescht werden (inklusive Spielstaende).

---

## 4. Pruefliste nach der Installation

1. `install.php` geloescht oder Sperrdatei vorhanden (Diagnose prueft das). Beim Paket ohne
   Assistent gibt es keine `install.php`; die Sperrdatei legt die Anwendung selbst an.
2. **Startpasswort geaendert.** Besonders wichtig beim Paket ohne Assistent: dort wird das
   dokumentierte Startpasswort automatisch gesetzt und ist damit oeffentlich bekannt.
   Der Adminbereich warnt, bis es geaendert wurde.
3. HTTPS aktiv (cPanel AutoSSL).
4. Diagnose ohne rote Punkte.
5. Stichprobe: `https://<domain>/storage/settings/settings.json` muss **403 oder 404** liefern.
6. Stichprobe: `https://<domain>/app/config.local.php` muss **403 oder 404** liefern.
7. Liegt das Spiel in einem Unterordner, zusaetzlich `https://<domain>/<ordner>/storage/...`
   pruefen.
8. Impressum und Datenschutzhinweis ausgefuellt.

---

## 5. Meldung von Sicherheitsproblemen

Diese Anwendung ist ein Spiel, kein Sicherheitsprodukt. Wer eine Luecke findet, sollte sie
direkt beim Betreiber der Installation melden. Fuer den Betrieb gilt: regelmaessige
Sicherungen, aktuelle PHP-Version, keine Weitergabe des Admin-Passworts.
