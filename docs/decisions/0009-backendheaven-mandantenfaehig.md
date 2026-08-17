# 0009 – backendHeaven mandantenfähig statt eine Installation je Kunde

**Status:** akzeptiert (Phase 0, August 2026) – umgesetzt ab Phase 15

## Kontext
Kunden sollen backendHeaven per Klick „installieren“ können. Klassische CMS-Systeme (z.B. WordPress)
werden dafür je Kunde einmal auf den Server kopiert. Für eine Node.js-Anwendung bedeutet das je
Installation einen eigenen Prozess.

## Entscheidung
**Eine backendHeaven-Laufzeit bedient viele Kundenwebsites.** Die Zuordnung erfolgt über die
angefragte Domain. „1-Klick-Installation“ legt an: Tenant, Datenbankschema, Zugangsdaten, Domain,
SSL, Adminbenutzer – **keinen neuen Serverprozess**.

## Begründung
- Ein Node-Prozess je Kunde braucht 150–250 MB RAM. Bei 4 GB wäre nach etwa fünf Kunden Schluss, und
  die Serverkosten würden linear mit der Kundenzahl wachsen. Mandantenfähigkeit hält sie flach:
  grob 20 Kunden für ~CHF 5/Monat statt ~CHF 50/Monat.
- Updates und Sicherheitspatches werden **einmal** ausgerollt statt pro Kunde – genau das Problem,
  an dem WordPress-Landschaften scheitern.
- Passt zur Produktphilosophie: wenige, gut gepflegte Funktionen statt Plugin-Wildwuchs.

## Alternativen
- **Prozess/Container je Kunde:** stärkste Isolation, aber unbezahlbar bei diesem Budget.
- **Statisch generierte Seiten je Kunde:** sehr günstig auszuliefern, aber Formulare, Shop und
  Vorschau werden umständlich. (Statische Auslieferung bleibt als spätere Optimierung offen.)
- **PHP-CMS je Kunde:** würde zum bestehenden Hosting passen, widerspricht aber der
  TypeScript-Architektur und dem Wunsch nach einem eigenen, modernen CMS.

## Konsequenzen – und die damit verbundene Pflicht
Die Mandantengrenze wird nicht mehr vom Betriebssystem erzwungen, sondern von der Anwendung.
Daraus folgt verbindlich:

- Jeder Datenzugriff läuft über eine Repository-Schicht, die `tenantId` **verpflichtend** verlangt.
- `tenantId` stammt immer aus der Sitzung bzw. der aufgelösten Domain, **nie** aus Benutzereingaben.
- Automatisierte Tests versuchen aktiv, fremde Tenants zu lesen und zu ändern; ein erfolgreicher
  Fremdzugriff bricht den Build.
- Medien liegen je Tenant in getrennten Verzeichnissen mit eigener Zugriffsprüfung.
- Kundeneigenes JavaScript/PHP bleibt deaktiviert – es würde die Trennung sofort aushebeln
  (siehe SECURITY.md).
- Ein Fehler in der Laufzeit betrifft alle Websites → Deployments nur mit grünen Tests und mit
  Health-Check plus Rollback.

## Wiedervorlage
Wenn ein Kunde vertraglich vollständige Isolation verlangt (dann eigener Container als kostenpflichtige
Option) oder wenn eine einzelne Website so viel Last erzeugt, dass sie andere beeinträchtigt.
