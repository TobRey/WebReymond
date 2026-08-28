// Packt games/heavenclimb/web/ in eine ZIP-Datei, die man auf jeden Webspace
// hochladen und dort entpacken kann.
//
// Warum ein eigener ZIP-Schreiber statt des zip-Befehls oder einer Bibliothek:
//   - Node hat keinen eingebauten ZIP-Schreiber, aber node:zlib kann Deflate.
//     Der Rest sind knapp hundert Zeilen Kopfdaten nach der PKZIP-Spezifikation.
//   - Das zip-Kommando gibt es unter Windows nicht, und die Entwicklungsumgebung
//     dieses Projekts ist laut docs/phase-2-entwicklungsumgebung.md Windows 11
//     mit WSL2. Ein Skript, das überall mit blossem Node läuft, ist dort
//     verlässlicher.
//   - Eine Bibliothek (archiver, jszip) bedeutet einen Lockfile-Eintrag und
//     einen Abhängigkeitsbaum für eine Datei von 60 kB. Das lohnt nicht.
//
// Die Zeitstempel sind fest verdrahtet: zwei Läufe ergeben dieselbe Datei,
// Byte für Byte. So sieht man an der Prüfsumme, ob sich wirklich etwas geändert
// hat.
//
// Aufruf:  pnpm game:zip

import { deflateRawSync, crc32 as zlibCrc32 } from 'node:zlib';
import { mkdirSync, readFileSync, readdirSync, statSync, writeFileSync } from 'node:fs';
import { basename, dirname, extname, join, relative, sep } from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = dirname(fileURLToPath(import.meta.url));
const SOURCE = join(HERE, 'web');
const OUT_DIR = join(HERE, 'dist');

/** Das Repository enthält bewusst keine Binärdaten. Diese Liste hält das so. */
const ALLOWED_EXTENSIONS = new Set(['.html', '.css', '.js', '.php', '.json', '.txt', '.md']);
const ALLOWED_NAMES = new Set(['.htaccess']);
const MAX_FILE_BYTES = 512 * 1024;

// Fester Zeitstempel: 1. Januar 2024, 00:00 Uhr. Das DOS-Format zählt Jahre ab
// 1980 und Sekunden in Zweierschritten; eine Null wäre formal ungültig.
const DOS_DATE = ((2024 - 1980) << 9) | (1 << 5) | 1;
const DOS_TIME = 0;

// ---------------------------------------------------------------------------
// CRC-32
// ---------------------------------------------------------------------------

let table = null;

function crc32Fallback(buffer) {
  if (table === null) {
    table = new Int32Array(256);
    for (let n = 0; n < 256; n += 1) {
      let c = n;
      for (let k = 0; k < 8; k += 1) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
      table[n] = c;
    }
  }
  let crc = -1;
  for (const byte of buffer) crc = table[(crc ^ byte) & 0xff] ^ (crc >>> 8);
  return (crc ^ -1) >>> 0;
}

// node:zlib bringt crc32 erst ab Node 22.2 mit – der Rückfall kostet nichts.
const crc32 = typeof zlibCrc32 === 'function' ? (buffer) => zlibCrc32(buffer) >>> 0 : crc32Fallback;

// ---------------------------------------------------------------------------
// Dateien einsammeln
// ---------------------------------------------------------------------------

/**
 * Was score.php zur Laufzeit anlegt, gehört nicht in die Auslieferung: die
 * Bestenliste des Entwicklungsrechners hätte auf einem fremden Webspace nichts
 * zu suchen, und die Sperrdatei erst recht nicht. Nur die .htaccess aus data/
 * wird mitgeliefert.
 */
function isRuntimeData(path) {
  const relativePath = relative(SOURCE, path).split(sep).join('/');
  return relativePath.startsWith('data/') && basename(path) !== '.htaccess';
}

function collect(directory, into = []) {
  for (const entry of readdirSync(directory, { withFileTypes: true }).sort((a, b) =>
    a.name < b.name ? -1 : a.name > b.name ? 1 : 0,
  )) {
    const full = join(directory, entry.name);
    if (entry.isDirectory()) {
      collect(full, into);
    } else if (entry.isFile() && !isRuntimeData(full)) {
      into.push(full);
    }
  }
  return into;
}

function checkFile(path) {
  const name = basename(path);
  const extension = extname(name).toLowerCase();
  if (!ALLOWED_NAMES.has(name) && !ALLOWED_EXTENSIONS.has(extension)) {
    throw new Error(
      `${path}: Endung "${extension || name}" ist nicht vorgesehen. ` +
        'HeavenClimb kommt ohne Binärdateien aus – wer das ändern will, erweitert ' +
        'ALLOWED_EXTENSIONS in pack.mjs bewusst.',
    );
  }
  const size = statSync(path).size;
  if (size > MAX_FILE_BYTES) {
    throw new Error(`${path}: ${size} Bytes überschreiten die Grenze von ${MAX_FILE_BYTES}.`);
  }
}

// ---------------------------------------------------------------------------
// ZIP schreiben
// ---------------------------------------------------------------------------

function localHeader(entry) {
  const header = Buffer.alloc(30);
  header.writeUInt32LE(0x04034b50, 0); // Signatur
  header.writeUInt16LE(20, 4); // benötigte Version 2.0
  header.writeUInt16LE(0, 6); // keine Sonderflags: Grösse und CRC stehen im Kopf
  header.writeUInt16LE(entry.method, 8);
  header.writeUInt16LE(DOS_TIME, 10);
  header.writeUInt16LE(DOS_DATE, 12);
  header.writeUInt32LE(entry.crc, 14);
  header.writeUInt32LE(entry.compressed.length, 18);
  header.writeUInt32LE(entry.raw.length, 22);
  header.writeUInt16LE(entry.nameBytes.length, 26);
  header.writeUInt16LE(0, 28); // kein Extrafeld
  return header;
}

function centralHeader(entry) {
  const header = Buffer.alloc(46);
  header.writeUInt32LE(0x02014b50, 0);
  header.writeUInt16LE(0x0314, 4); // erzeugt unter Unix, Version 2.0
  header.writeUInt16LE(20, 6);
  header.writeUInt16LE(0, 8);
  header.writeUInt16LE(entry.method, 10);
  header.writeUInt16LE(DOS_TIME, 12);
  header.writeUInt16LE(DOS_DATE, 14);
  header.writeUInt32LE(entry.crc, 16);
  header.writeUInt32LE(entry.compressed.length, 20);
  header.writeUInt32LE(entry.raw.length, 24);
  header.writeUInt16LE(entry.nameBytes.length, 28);
  header.writeUInt16LE(0, 30); // Extrafeld
  header.writeUInt16LE(0, 32); // Kommentar
  header.writeUInt16LE(0, 34); // Datenträger
  header.writeUInt16LE(0, 36); // interne Attribute
  // Externe Attribute: reguläre Datei mit 0644, damit das Entpacken unter
  // Linux vernünftige Rechte ergibt.
  header.writeUInt32LE((0o100644 << 16) >>> 0, 38);
  header.writeUInt32LE(entry.offset, 42);
  return header;
}

function buildZip(entries) {
  const parts = [];
  let offset = 0;

  for (const entry of entries) {
    entry.offset = offset;
    const header = localHeader(entry);
    parts.push(header, entry.nameBytes, entry.compressed);
    offset += header.length + entry.nameBytes.length + entry.compressed.length;
  }

  const directoryStart = offset;
  for (const entry of entries) {
    const header = centralHeader(entry);
    parts.push(header, entry.nameBytes);
    offset += header.length + entry.nameBytes.length;
  }
  const directorySize = offset - directoryStart;

  const end = Buffer.alloc(22);
  end.writeUInt32LE(0x06054b50, 0);
  end.writeUInt16LE(0, 4);
  end.writeUInt16LE(0, 6);
  end.writeUInt16LE(entries.length, 8);
  end.writeUInt16LE(entries.length, 10);
  end.writeUInt32LE(directorySize, 12);
  end.writeUInt32LE(directoryStart, 16);
  end.writeUInt16LE(0, 20);
  parts.push(end);

  return Buffer.concat(parts);
}

// ---------------------------------------------------------------------------
// Ablauf
// ---------------------------------------------------------------------------

function readVersion() {
  const source = readFileSync(join(SOURCE, 'src', 'version.js'), 'utf8');
  const match = source.match(/VERSION\s*=\s*'([^']+)'/);
  if (match === null) throw new Error('In web/src/version.js steht keine VERSION.');
  return match[1];
}

function pad(text, width) {
  return String(text).padEnd(width);
}

function padLeft(text, width) {
  return String(text).padStart(width);
}

function main() {
  const version = readVersion();
  const files = collect(SOURCE);
  if (files.length === 0) throw new Error(`Keine Dateien unter ${SOURCE} gefunden.`);

  const entries = files.map((path) => {
    checkFile(path);
    const name = relative(SOURCE, path).split(sep).join('/');
    if (!/^[\x20-\x7e]+$/.test(name)) {
      throw new Error(`${name}: nur ASCII-Dateinamen, sonst gibt es beim Entpacken Kauderwelsch.`);
    }
    const raw = readFileSync(path);
    const deflated = deflateRawSync(raw, { level: 9 });
    // Bei sehr kleinen Dateien ist das Ergebnis manchmal grösser als das
    // Original – dann unkomprimiert ablegen.
    const useDeflate = deflated.length < raw.length;
    return {
      name,
      nameBytes: Buffer.from(name, 'ascii'),
      raw,
      compressed: useDeflate ? deflated : raw,
      method: useDeflate ? 8 : 0,
      crc: crc32(raw),
      offset: 0,
    };
  });

  const zip = buildZip(entries);
  mkdirSync(OUT_DIR, { recursive: true });
  const versioned = join(OUT_DIR, `heavenclimb-${version}.zip`);
  const stable = join(OUT_DIR, 'heavenclimb.zip');
  writeFileSync(versioned, zip);
  writeFileSync(stable, zip);

  const rawTotal = entries.reduce((sum, e) => sum + e.raw.length, 0);
  console.log(`HeavenClimb ${version}\n`);
  console.log(`${pad('Datei', 34)}${padLeft('roh', 9)}${padLeft('gepackt', 10)}`);
  console.log('-'.repeat(53));
  for (const entry of entries) {
    console.log(
      `${pad(entry.name, 34)}${padLeft(entry.raw.length, 9)}${padLeft(entry.compressed.length, 10)}`,
    );
  }
  console.log('-'.repeat(53));
  console.log(
    `${pad(`${entries.length} Dateien`, 34)}${padLeft(rawTotal, 9)}${padLeft(zip.length, 10)}`,
  );
  console.log(`\n${stable}`);
  console.log(`${versioned}`);
}

main();
