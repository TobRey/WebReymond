// Ein Fehler darf nicht stumm bleiben.
//
// WARUM ES DIESE DATEI GIBT
// Gemeldet war: „Auf Starten klicken, es passiert nichts." Der Menübildschirm
// ist statisches HTML – er steht auch dann da, wenn das JavaScript nie
// durchgelaufen ist. Ein Fehler beim Start sieht deshalb exakt so aus wie ein
// kaputter Knopf, und auf einem Handy gibt es keine Konsole, in der die Antwort
// stünde. Ohne diese Datei ist so ein Fehler aus der Ferne nicht zu finden.
//
// Der Streifen ist bewusst hässlich und ganz oben: er soll nicht übersehen
// werden. Der Text ist auswählbar, damit man ihn abschicken kann – das ist der
// eigentliche Zweck.

const MAX_LINES = 4;

let element = null;
const seen = new Set();

function ensure() {
  if (element !== null) return element;
  element = document.getElementById('crash');
  return element;
}

/** Kurzfassung eines Fehlers: Meldung, Datei, Zeile. */
function describe(error, source, line, column) {
  const message =
    error instanceof Error ? `${error.name}: ${error.message}` : String(error ?? 'Unbekannt');
  // Nur der Dateiname, nicht die ganze Adresse – die ist auf dem Handy so lang,
  // dass die eigentliche Meldung aus dem Bild läuft.
  const file = typeof source === 'string' ? source.split('/').pop() : '';
  const where = file ? ` (${file}${line ? `:${line}${column ? `:${column}` : ''}` : ''})` : '';
  return message + where;
}

/**
 * Zeigt eine Zeile im Meldungsstreifen.
 *
 * Mehrfach dieselbe Meldung wird nicht wiederholt: ein Fehler in der
 * Spielschleife käme sonst sechzigmal je Sekunde.
 */
export function report(text) {
  const box = ensure();
  if (box === null) return;
  if (seen.has(text) || seen.size >= MAX_LINES) return;
  seen.add(text);

  const line = document.createElement('p');
  line.className = 'crash__line';
  line.textContent = text;
  box.querySelector('.crash__list')?.append(line);
  box.hidden = false;
}

/** Meldet einen gefangenen Fehler. */
export function reportError(error, context = '') {
  report(context ? `${context}: ${describe(error)}` : describe(error));
}

/**
 * Hängt sich an alles, was sonst nur in der Konsole landet.
 *
 * Der eigentliche Fänger sitzt nicht hier, sondern als kurzes Skript direkt in
 * index.html – und das aus einem Grund, der genau den gemeldeten Fehler trifft:
 * ein Modul, das gar nicht erst ausgeführt wird (Datei fehlt, unvollständig
 * hochgeladen, vom Server mit falschem Typ ausgeliefert, Syntaxfehler), kann
 * sich unmöglich selbst darüber beschweren. Dieses Skript sammelt schon vorher.
 *
 * Hier wird die Sammlung nur übernommen: das Bisherige nachgetragen, das
 * Kommende weitergereicht.
 */
export function watchForErrors() {
  const early = window.mogliBoot;
  if (early !== undefined && early !== null) {
    early.onError = report;
    for (const line of early.errors) report(line);
    return;
  }

  // Ohne das Skript in index.html (jemand hat die Datei von Hand gekürzt):
  // dann wenigstens ab jetzt zuhören.
  window.addEventListener('error', (event) => {
    // Auch fehlgeschlagene Ressourcen (ein Modul, das 404 gibt) landen hier,
    // dann steckt die Ursache im Ziel und nicht in einer Meldung.
    if (event.target && event.target !== window && event.target.src) {
      report(`Datei nicht ladbar: ${String(event.target.src).split('/').pop()}`);
      return;
    }
    report(describe(event.error ?? event.message, event.filename, event.lineno, event.colno));
  });

  window.addEventListener('unhandledrejection', (event) => {
    report(describe(event.reason));
  });
}

/**
 * Meldet, dass das Spiel bedienbar ist.
 *
 * Der Wachhund in index.html schlägt sonst kurz nach dem Laden an. Aufgerufen
 * wird das, sobald die Knöpfe verdrahtet sind – nicht erst am Ende von boot(),
 * denn ab da ist das Menü benutzbar, und nur darum geht es dem Wachhund.
 */
export function ready() {
  const early = window.mogliBoot;
  if (early !== undefined && early !== null) early.ok = true;
}

/**
 * Führt etwas aus und meldet, wenn es schiefgeht – ohne den Rest mitzureissen.
 * Damit kann eine kaputte Kleinigkeit nicht mehr den ganzen Start verhindern.
 */
export function guarded(context, fn) {
  try {
    fn();
    return true;
  } catch (error) {
    reportError(error, context);
    return false;
  }
}
