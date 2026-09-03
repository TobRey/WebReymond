#!/usr/bin/env python3
"""
Feldfunktionen nach Norm schreiben.

docx-js packt Anfang, Anweisung, Trenner und Ende einer Feldfunktion in
einen einzigen Lauf und lässt das Ergebnis weg. Word kommt damit klar,
andere Betrachter nicht: Ohne formatiertes Ergebnis setzen sie die
errechnete Zahl in der Grundgrösse - im Fuss stand dann "Seite 1 von 1"
mit winzigen Wörtern und grossen Zahlen.

Also aufgeteilt, wie es die Norm vorsieht: je ein Lauf für Anfang,
Anweisung, Trenner, Ergebnis und Ende - alle mit derselben Formatierung.
"""
import re
import shutil
import sys
import zipfile
from pathlib import Path

# Die Formatierung darf keine Lauf-Grenze überschreiten. Ohne diese
# Bremse dehnt sich ".*?" bis zum nächsten Feld und verschluckt alles
# dazwischen - beim ersten Versuch war das der halbe Fuss.
FELD = re.compile(
    r'<w:r>(<w:rPr>(?:(?!</?w:r[ >]).)*?</w:rPr>)'
    r'<w:fldChar w:fldCharType="begin"/>'
    r'<w:instrText xml:space="preserve">((?:(?!</?w:r[ >]).)*?)</w:instrText>'
    r'<w:fldChar w:fldCharType="separate"/>'
    r'<w:fldChar w:fldCharType="end"/></w:r>',
    re.S,
)


def richten(xml: str) -> tuple[str, int]:
    anzahl = 0

    def ersetzen(m: re.Match) -> str:
        nonlocal anzahl
        anzahl += 1
        rpr, anweisung = m.group(1), m.group(2).strip()
        lauf = f'<w:r>{rpr}'
        return (
            f'{lauf}<w:fldChar w:fldCharType="begin"/></w:r>'
            f'{lauf}<w:instrText xml:space="preserve"> {anweisung} </w:instrText></w:r>'
            f'{lauf}<w:fldChar w:fldCharType="separate"/></w:r>'
            f'{lauf}<w:t>1</w:t></w:r>'
            f'{lauf}<w:fldChar w:fldCharType="end"/></w:r>'
        )

    return FELD.sub(ersetzen, xml), anzahl


def main() -> int:
    quelle = Path(sys.argv[1])
    tmp = quelle.with_suffix('.tmp.docx')
    gesamt = 0

    with zipfile.ZipFile(quelle) as alt, \
            zipfile.ZipFile(tmp, 'w', zipfile.ZIP_DEFLATED) as neu:
        for eintrag in alt.infolist():
            daten = alt.read(eintrag.filename)

            if eintrag.filename.startswith('word/') and eintrag.filename.endswith('.xml'):
                text, n = richten(daten.decode('utf-8'))
                gesamt += n
                daten = text.encode('utf-8')

            neu.writestr(eintrag, daten)

    # Erst prüfen, dann übernehmen. Ein kaputtes XML fällt sonst erst
    # auf, wenn Word die Datei nicht mehr öffnet.
    fehler = pruefen(tmp)

    if fehler:
        tmp.unlink()
        print('  ABGEBROCHEN: ' + fehler)

        return 1

    shutil.move(str(tmp), str(quelle))
    print(f'  Feldfunktionen nach Norm geschrieben: {gesamt}')

    return 0


def pruefen(datei: Path) -> str:
    """Lässt sich jedes XML darin noch einlesen?"""
    import xml.etree.ElementTree as ET

    try:
        with zipfile.ZipFile(datei) as z:
            if z.testzip() is not None:
                return 'Das Archiv ist beschädigt.'

            for name in z.namelist():
                if name.endswith('.xml') or name.endswith('.rels'):
                    try:
                        ET.fromstring(z.read(name))
                    except ET.ParseError as e:
                        return f'{name} lässt sich nicht mehr einlesen: {e}'
    except Exception as e:  # noqa: BLE001
        return str(e)

    return ''


if __name__ == '__main__':
    raise SystemExit(main())
