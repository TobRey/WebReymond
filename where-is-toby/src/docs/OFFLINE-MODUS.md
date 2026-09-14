# Offline-Modus: das regelbasierte Dialogsystem

Ohne KI-Anbieter antworten alle NPCs ueber ein regelbasiertes System. Es ist kein Platzhalter,
sondern eine vollwertige Betriebsart: Der Fall "Toby" ist damit vollstaendig loesbar - inklusive
Luegen, Konfrontationen, Gestaendnissen und freigeschalteten Beweisen.

---

## 1. Ablauf einer Antwort

1. **Eingabe normalisieren:** Kleinschreibung, Umlaute (ae/oe/ue), Satzzeichen entfernen.
2. **Konfrontation pruefen:** Wurde ein Beweis vorgelegt? Dann entscheidet der Eintrag
   `offline.confront[BEWEIS-ID]` der Figur.
3. **Regelwerk auswerten:** Jede Regel hat Schluesselwoerter (`any`), Pflichtwoerter (`all`),
   Bedingungen (`requires_flags`, `requires_evidence`, `min_trust`, `max_stress`) und eine
   Priorität. Die Regel mit der hoechsten Trefferzahl gewinnt. Mehrwort-Treffer wiegen mehr,
   laengere Woerter werden mit Tippfehler-Toleranz (Levenshtein 1-2) erkannt.
4. **Allgemeine Absicht:** Begruessung, Verabschiedung, Dank, Vorwurf, Hilfe, Alibi,
   "Bist du eine KI?" - dafuer hat jede Figur eigene Antworten.
5. **Rueckfall:** rotierende, figurentypische Ausweichantworten.

Zusaetzlich verwaltet das System **Vertrauen** und **Stress** je Figur. Bei sehr hohem Stress
bricht die Figur das Gespraech ab (`leave_seconds`) und ist kurz nicht erreichbar.

---

## 2. Was die Figuren koennen

| Verhalten | Umsetzung |
|---|---|
| Luegen | `alibi_claimed` gegen `alibi_actual`, Luegen mit `evidence` und `confession` |
| Einlenken | `offline.confront` aendert Aussage, setzt `flags`, gibt Beweise frei |
| Ausweichen | Regeln mit hoher Priorität, die nur Andeutungen enthalten |
| Emotionen | `effects.stress` / `effects.trust` je Regel, Abbruch bei Stress ≥ 85 |
| Eigener Schreibstil | Antworttexte sind im Stil der Figur geschrieben (z. B. Kleinschreibung) |
| Ungefragte Nachrichten | `proactive` mit Bedingungen (`requires_flags`, `min_messages`) |
| Nichts wissen | `unknown_topics` und bewusst fehlende Regeln |

---

## 3. Unterschiede zum KI-Modus

| | Offline | KI |
|---|---|---|
| Freie Formulierungen | Schluesselwoerter + Tippfehler-Toleranz | volles Sprachverstaendnis |
| Antwortvielfalt | mehrere vorbereitete Varianten je Regel | frei formuliert |
| Gedaechtnis | Zustand (Vertrauen, Stress, genutzte Regeln) | zusaetzlich die letzten 12 Nachrichten |
| Loesbarkeit | vollstaendig | vollstaendig |
| Kosten | keine | Kontingent des Anbieters |

Der verdeckte Zustandsblock (Vertrauen, Stress, Freischaltungen) funktioniert in beiden
Betriebsarten identisch: Im KI-Modus liest der Server ihn aus der Antwort, im Offline-Modus
liefern die Regeln ihn direkt. Er wird dem Spieler nie angezeigt.

---

## 4. Eigene Dialogregeln schreiben

Im Fall-Editor unter **Personen → Offline: Dialogregeln**:

```json
{
  "id": "r_nora_lantern",
  "any": ["lantern", "anonym", "forum", "insider"],
  "priority": 7,
  "min_trust": 0,
  "requires_evidence": [],
  "reply": [
    "irgendein typ aus dem forum. er hat gesagt er arbeitet beim wasserwerk."
  ],
  "effects": { "trust": 5, "flags": ["lantern_bekannt"], "evidence": [] }
}
```

Tipps:

* 4-8 Schluesselwoerter je Regel sind ein guter Wert, inklusive Synonymen und Uhrzeiten.
* Mehrere Antwortvarianten in `reply` verhindern Wiederholungsgefuehl.
* Wichtige Informationen nur ueber `requires_evidence` freigeben - das erzwingt echte
  Ermittlungsarbeit.
* Fuer jede Luege eine Konfrontation in `offline.confront` hinterlegen und dort
  `solved_flag` der Luege setzen, damit die Auswertung sie als aufgedeckt zaehlt.
