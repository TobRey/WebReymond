/**
 * Die WebAtze-Briefvorlage.
 *
 * Aufbau nach Schweizer Brief: Absender oben, Empfänger im Fensterfeld,
 * Datum rechts, Betreff fett. Was die Gestaltung ausmacht, sind drei
 * Dinge - das Logo, die Verlaufslinie darunter, und der ruhige,
 * dreispaltige Fuss. Alles andere hält sich zurück, damit der Text wirkt.
 */
const {
  Document, Packer, Paragraph, TextRun, ImageRun, Header, Footer,
  Table, TableRow, TableCell, WidthType, AlignmentType, BorderStyle,
  ShadingType, VerticalAlign, PageNumber, TabStopType, TabStopPosition,
  convertMillimetersToTwip, LevelFormat, HeadingLevel,
} = require('docx');
const fs = require('fs');
const path = require('path');

const HIER = __dirname;
const INDIGO = '2B1B9E';
const TUERKIS = '17C8C8';
const GRAU = '6B7280';
const GRAU_HELL = '9AA1AC';
const SCHWARZ = '1A1A2E';

const SCHRIFT = 'Calibri';
const SCHRIFT_TITEL = 'Trebuchet MS';

// Nutzbare Breite: A4 minus Ränder. In Punkten für Bilder (96 dpi).
const RAND_LINKS = convertMillimetersToTwip(25);
const RAND_RECHTS = convertMillimetersToTwip(20);
const BREITE_TWIP = convertMillimetersToTwip(210) - RAND_LINKS - RAND_RECHTS;
const BREITE_PX = Math.round((165 / 25.4) * 96); // 165 mm

const bild = (name) => fs.readFileSync(path.join(HIER, name));

/** Eine Zeile kleiner, grauer Text. */
const klein = (text, opts = {}) => new TextRun({
  text, size: opts.size ?? 15, color: opts.color ?? GRAU,
  font: opts.font ?? SCHRIFT, bold: opts.bold ?? false,
  characterSpacing: opts.spacing,
});

const leer = (nachher = 0) => new Paragraph({ spacing: { after: nachher }, children: [] });

/* ------------------------------------------------------------------ */
/* Kopfzeile                                                           */
/* ------------------------------------------------------------------ */

const absender = [
  ['Tobias Reymond', true],
  ['[Strasse und Nummer]', false],
  ['[PLZ und Ort]', false],
  ['', false],
  ['[Telefonnummer]', false],
  ['[E-Mail-Adresse]', false],
  ['webatze.ch', false],
];

const kopfTabelle = new Table({
  columnWidths: [Math.round(BREITE_TWIP * 0.55), Math.round(BREITE_TWIP * 0.45)],
  width: { size: BREITE_TWIP, type: WidthType.DXA },
  borders: {
    top: { style: BorderStyle.NONE }, bottom: { style: BorderStyle.NONE },
    left: { style: BorderStyle.NONE }, right: { style: BorderStyle.NONE },
    insideHorizontal: { style: BorderStyle.NONE }, insideVertical: { style: BorderStyle.NONE },
  },
  rows: [
    new TableRow({
      children: [
        new TableCell({
          width: { size: Math.round(BREITE_TWIP * 0.55), type: WidthType.DXA },
          margins: { top: 0, bottom: 0, left: 0, right: 0 },
          verticalAlign: VerticalAlign.TOP,
          children: [
            new Paragraph({
              spacing: { after: 0 },
              children: [new ImageRun({
                type: 'png', data: bild('logo.png'),
                transformation: { width: 196, height: 54 },
              })],
            }),
          ],
        }),
        new TableCell({
          width: { size: Math.round(BREITE_TWIP * 0.45), type: WidthType.DXA },
          margins: { top: 0, bottom: 0, left: 0, right: 0 },
          verticalAlign: VerticalAlign.TOP,
          children: absender.map(([zeile, fett]) => new Paragraph({
            alignment: AlignmentType.RIGHT,
            spacing: { after: 0, line: 240 },
            children: zeile === '' ? [] : [klein(zeile, {
              bold: fett,
              color: fett ? SCHWARZ : GRAU,
              size: fett ? 17 : 15,
              font: fett ? SCHRIFT_TITEL : SCHRIFT,
            })],
          })),
        }),
      ],
    }),
  ],
});

const kopf = new Header({
  children: [
    kopfTabelle,
    new Paragraph({
      spacing: { before: 140, after: 0 },
      children: [new ImageRun({
        type: 'png', data: bild('regel.png'),
        transformation: { width: BREITE_PX, height: 4 },
      })],
    }),
  ],
});

/* ------------------------------------------------------------------ */
/* Fusszeile                                                           */
/* ------------------------------------------------------------------ */

const fussSpalten = [
  ['WEBATZE', ['Tobias Reymond', '[Strasse und Nummer]', '[PLZ und Ort]']],
  ['KONTAKT', ['[Telefonnummer]', '[E-Mail-Adresse]', 'webatze.ch']],
  ['ZAHLUNG', ['[Bank]', 'IBAN [CHxx xxxx xxxx xxxx x]', 'MWST-Nr. [CHE-xxx.xxx.xxx]']],
];

const spaltenBreite = Math.floor(BREITE_TWIP / 3);

const fussTabelle = new Table({
  columnWidths: [spaltenBreite, spaltenBreite, BREITE_TWIP - 2 * spaltenBreite],
  width: { size: BREITE_TWIP, type: WidthType.DXA },
  borders: {
    top: { style: BorderStyle.NONE }, bottom: { style: BorderStyle.NONE },
    left: { style: BorderStyle.NONE }, right: { style: BorderStyle.NONE },
    insideHorizontal: { style: BorderStyle.NONE }, insideVertical: { style: BorderStyle.NONE },
  },
  rows: [
    new TableRow({
      children: fussSpalten.map(([titel, zeilen], i) => new TableCell({
        width: {
          size: i === 2 ? BREITE_TWIP - 2 * spaltenBreite : spaltenBreite,
          type: WidthType.DXA,
        },
        margins: { top: 0, bottom: 0, left: 0, right: i === 2 ? 0 : 200 },
        children: [
          new Paragraph({
            spacing: { after: 40, line: 240 },
            children: [klein(titel, {
              bold: true, color: INDIGO, size: 13,
              font: SCHRIFT_TITEL, spacing: 20,
            })],
          }),
          ...zeilen.map((z) => new Paragraph({
            spacing: { after: 0, line: 230 },
            children: [klein(z, { size: 14, color: GRAU })],
          })),
        ],
      })),
    }),
  ],
});

const fuss = new Footer({
  children: [
    new Paragraph({
      spacing: { after: 120 },
      children: [new ImageRun({
        type: 'png', data: bild('regel-hell.png'),
        transformation: { width: BREITE_PX, height: 1 },
      })],
    }),
    fussTabelle,
    new Paragraph({
      alignment: AlignmentType.RIGHT,
      spacing: { before: 160 },
      children: [
        new TextRun({ text: 'Seite ', size: 14, color: GRAU_HELL, font: SCHRIFT }),
        new TextRun({ children: [PageNumber.CURRENT], size: 14, color: GRAU_HELL, font: SCHRIFT }),
        new TextRun({ text: ' von ', size: 14, color: GRAU_HELL, font: SCHRIFT }),
        new TextRun({ children: [PageNumber.TOTAL_PAGES], size: 14, color: GRAU_HELL, font: SCHRIFT }),
      ],
    }),
  ],
});

/* ------------------------------------------------------------------ */
/* Der Brief                                                           */
/* ------------------------------------------------------------------ */

const empfaenger = ['[Firma]', '[Vorname Name]', '[Strasse und Nummer]', '[PLZ und Ort]'];

const koerper = [
  // Empfängerfeld - es beginnt rund 55 mm unter der Blattkante und
  // trifft damit das Sichtfenster eines C5- oder C6-Kuverts. Der
  // Abstand nach oben hält es zugleich von der Verlaufslinie fern.
  ...empfaenger.map((zeile, i) => new Paragraph({
    spacing: { after: 0, line: 280, before: i === 0 ? 340 : 0 },
    children: [new TextRun({
      text: zeile, size: 21, font: SCHRIFT,
      color: i === 0 ? SCHWARZ : SCHWARZ, bold: i === 0,
    })],
  })),

  leer(0), leer(0), leer(0),

  // Ort und Datum
  new Paragraph({
    alignment: AlignmentType.RIGHT,
    spacing: { after: 480 },
    children: [new TextRun({ text: '[Ort], [Datum]', size: 21, font: SCHRIFT, color: GRAU })],
  }),

  // Betreff
  new Paragraph({
    spacing: { after: 360 },
    children: [new TextRun({
      text: '[Betreff der Nachricht]',
      bold: true, size: 25, font: SCHRIFT_TITEL, color: INDIGO,
    })],
  }),

  new Paragraph({
    spacing: { after: 240, line: 300 },
    children: [new TextRun({ text: 'Guten Tag [Anrede]', size: 21, font: SCHRIFT })],
  }),

  new Paragraph({
    spacing: { after: 240, line: 300 },
    children: [new TextRun({
      text: 'Hier steht der Text. Ein Absatz sagt eine Sache; was danach kommt, '
        + 'sagt die nächste. Wer zwei Gedanken in einen Absatz packt, zwingt den '
        + 'Leser, sie selbst zu trennen.',
      size: 21, font: SCHRIFT,
    })],
  }),

  new Paragraph({
    spacing: { after: 480, line: 300 },
    children: [new TextRun({
      text: 'Für Rückfragen stehe ich gerne zur Verfügung.',
      size: 21, font: SCHRIFT,
    })],
  }),

  new Paragraph({
    spacing: { after: 0, line: 300 },
    children: [new TextRun({ text: 'Freundliche Grüsse', size: 21, font: SCHRIFT })],
  }),

  leer(0), leer(0), leer(0),

  new Paragraph({
    spacing: { after: 0 },
    children: [new TextRun({ text: 'Tobias Reymond', size: 21, font: SCHRIFT, bold: true })],
  }),
  new Paragraph({
    spacing: { after: 0 },
    children: [klein('WebAtze', { size: 18 })],
  }),
];

/* ------------------------------------------------------------------ */

const doc = new Document({
  creator: 'WebAtze',
  title: 'WebAtze Briefvorlage',
  description: 'Briefvorlage mit Kopf- und Fusszeile',
  styles: {
    default: {
      document: { run: { font: SCHRIFT, size: 21, color: SCHWARZ } },
      heading1: {
        run: { font: SCHRIFT_TITEL, size: 32, bold: true, color: INDIGO },
        paragraph: { spacing: { before: 360, after: 180 } },
      },
      heading2: {
        run: { font: SCHRIFT_TITEL, size: 25, bold: true, color: SCHWARZ },
        paragraph: { spacing: { before: 300, after: 140 } },
      },
      heading3: {
        run: { font: SCHRIFT_TITEL, size: 22, bold: true, color: TUERKIS },
        paragraph: { spacing: { before: 240, after: 120 } },
      },
    },
  },
  sections: [{
    properties: {
      page: {
        size: { width: convertMillimetersToTwip(210), height: convertMillimetersToTwip(297) },
        margin: {
          top: convertMillimetersToTwip(48),
          right: RAND_RECHTS,
          bottom: convertMillimetersToTwip(36),
          left: RAND_LINKS,
          header: convertMillimetersToTwip(14),
          footer: convertMillimetersToTwip(12),
        },
      },
    },
    headers: { default: kopf },
    footers: { default: fuss },
    children: koerper,
  }],
});

Packer.toBuffer(doc).then((buf) => {
  const ziel = path.join(HIER, 'WebAtze-Briefvorlage.docx');
  fs.writeFileSync(ziel, buf);
  console.log('geschrieben:', ziel, Math.round(buf.length / 1024) + ' KB');
});
