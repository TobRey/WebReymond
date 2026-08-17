# Marke und Domain (Phase 1)

**Der Projektname bleibt `WebHeaven`.** Hier geht es nur um die Frage, unter welcher Domain die
Plattform erreichbar sein soll – und um ein Risiko, das vor der Registrierung geklärt sein muss.

---

## 1. Status von `webheaven.com`: ungeprüft

Aus der Entwicklungsumgebung heraus konnte die Verfügbarkeit **nicht** geprüft werden: WHOIS, RDAP
und DNS-Abfragen sind durch die Netzwerkregeln blockiert. Es wird deshalb **nichts** über die
Verfügbarkeit behauptet – die Prüfung erfolgt in Phase 1 von Hand (Anleitung unten).

## 2. Was die Websuche zeigt – und warum das wichtig ist

Diese Namen existieren bereits:

| Domain | Was dort ist |
|---|---|
| `webheaven.net` | **aktiver Webhosting-Anbieter** (Shared/Reseller-Hosting, SSL, 1-Klick-Installationen) |
| `webheaven.com.au` | **Webhosting und Domains**, Australien |
| `webheaven.org` | aktive Website |
| `webheaven.com.ua` | aktive Website |
| „Webheaven“ | IT-Dienstleister in Delhi, u.a. mit LinkedIn-Auftritt |

Das ist weniger ein Domainproblem als ein **Markenproblem**: Zwei dieser Anbieter sind in derselben
Branche tätig. Folgen für WebHeaven:

- **Auffindbarkeit:** Wer „WebHeaven Hosting“ sucht, landet zuerst bei der bestehenden Konkurrenz.
- **Verwechslung:** Kundenanfragen und Supportfälle können falsch adressiert werden.
- **Rechtliches Risiko:** Bei gleicher Branche und ähnlichem Namen ist ein Namensstreit möglich –
  besonders unangenehm, wenn die Marke schon auf Rechnungen, Verträgen und Kundendomains steht.

Das ist kein Grund zur Panik, aber ein Grund, es **vor** der Registrierung zu prüfen und bewusst zu
entscheiden.

## 3. Domain-Kandidaten zum Selbstprüfen

Reihenfolge = Empfehlung.

| Kandidat | Gedanke | Zu beachten |
|---|---|---|
| **webheaven.ch** | Marke bleibt, klarer Schweiz-Bezug – passt zur Zielgruppe | Verwechslungsrisiko mit `webheaven.net` bleibt bestehen |
| **webhaven.ch** / **webhaven.com** | „Haven“ = sicherer Hafen. Semantisch näher an Hosting („deine Website ist hier sicher“), klar unterscheidbar | Sehr ähnliche Schreibweise – bewusst gewählt oder verwirrend? |
| **sitehaven.ch** | Wie oben, aber eindeutiger von „Heaven“ getrennt | – |
| **heavenhost.ch** / **hostheaven.ch** | Wortstellung getauscht, Hosting im Namen | Prüfen, ob ebenfalls belegt |
| **webheaven.swiss** | Starkes Vertrauenssignal im Schweizer Markt | `.swiss` ist **eingeschränkt**: Es braucht einen Bezug zur Schweiz (in der Regel Handelsregistereintrag). Vor Bestellung die Kriterien bei der Registerstelle prüfen |
| **heavencloud.ch**, **nimbo.ch**, **zenithhost.ch** | Rückfallebene mit eigenem Namensraum | Kunstnamen brauchen mehr Marketing, sind dafür rechtlich am saubersten |

**Empfehlung:** Zuerst prüfen, ob `webheaven.ch` **und** `webheaven.com` frei sind. Sind beide frei,
ist die Sache einfach. Ist `.com` belegt (wahrscheinlich), lohnt der Vergleich zwischen
„nur `.ch` nehmen“ und „auf `webhaven`/`sitehaven` ausweichen und beide Endungen sichern“.

## 4. So prüfst du selbst (Windows 11, ohne Vorkenntnisse)

### Schritt 1 – Verfügbarkeit über einen Registrar
Öffne die Domainsuche eines seriösen Registrars (z.B. INWX oder Infomaniak) und gib den Namen ohne
Endung ein. Angezeigt werden Verfügbarkeit und Preis pro Endung.
*Ergebnis:* „verfügbar“ oder „vergeben“ – plus **Verlängerungspreis** notieren, nicht nur den
Aktionspreis des ersten Jahres.

### Schritt 2 – Wer besitzt die Domain? (`.com`)
Öffne <https://lookup.icann.org> und gib `webheaven.com` ein.
*Ergebnis:* Existiert ein Eintrag mit Registrierungsdatum, ist die Domain vergeben. „No matching
record“ bedeutet frei.

### Schritt 3 – Wer besitzt die Domain? (`.ch`)
Öffne <https://www.nic.ch/whois/> und gib den Namen ein.
*Ergebnis:* `.ch`-Auskünfte sind eingeschränkt, aber „nicht registriert“ vs. „registriert“ wird
zuverlässig angezeigt.

### Schritt 4 – Markenrecherche (10 Minuten, spart später viel Ärger)
- Schweiz: <https://www.swissreg.ch> → Marken → nach „webheaven“, „web heaven“, „webhaven“ suchen
- Europa/international: <https://www.tmdn.org/tmview/> → dieselben Begriffe

*Ergebnis:* Gibt es eine eingetragene Marke in der Klasse für IT-/Hostingdienstleistungen (Klasse 42,
teilweise 38), ist der Name riskant. Findet sich nichts, ist das Risiko gering – aber nicht null,
denn auch nicht eingetragene, tatsächlich benutzte Kennzeichen können Rechte begründen.

### Schritt 5 – Entscheidung festhalten
Ergebnis (Name, Endung, Preis, Registrar, Datum) hier im Repository ergänzen und als ADR unter
`docs/decisions/` festhalten. Erst danach registrieren.

## 5. Nach der Registrierung – sofort erledigen

- [ ] **Auto-Renew aktivieren** (eine vergessene Verlängerung kostet die Domain)
- [ ] **Zwei-Faktor-Authentifizierung im Registrar-Konto** aktivieren
- [ ] **Transfer-Sperre (Registrar-Lock)** aktivieren
- [ ] E-Mail-Adresse des Kontos auf eine Adresse legen, die **nicht** von dieser Domain abhängt
      (sonst sperrt man sich bei Problemen selbst aus)
- [ ] Erst danach: Nameserver/DNS konfigurieren (Phase 7/8)

## 6. Wenn der Wunschname vergeben ist

Nicht auf kreative Schreibweisen ausweichen, die zu Tippfehlern einladen (`web-heaven`, `webheav3n`,
`wehbeaven`). Besser ein anderer, klarer Name als eine Variante, die man am Telefon buchstabieren
muss. Der Projektname im Repository kann `WebHeaven` bleiben, auch wenn die Marke nach aussen anders
heisst – umbenennen lässt sich später, eine verlorene Marke nicht.
