/**
 * Verwaltungsbereich – Bedienung.
 *
 * Bewusst schlank: kein Rahmenwerk, keine grossen Abhängigkeiten. Alles,
 * was hier passiert, ist gut ohne. Die Seite funktioniert auch, wenn
 * dieses Skript nicht lädt – dann eben ohne Fortschrittsanzeige.
 */

import './admin.css';

document.documentElement.classList.add('js');

/* ------------------------------------------------------------------ */
/* Anfragen an die eigene Schnittstelle                                */
/* ------------------------------------------------------------------ */

/**
 * Schickt Daten an den Server und gibt die Antwort zurück.
 * Das Sicherheitszeichen wird automatisch mitgeschickt.
 */
export async function api(url, { method = 'POST', data = null } = {}) {
  const options = {
    method,
    headers: {
      Accept: 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
      'X-CSRF-Token': window.WA_CSRF ?? '',
    },
  };

  if (data instanceof FormData) {
    options.body = data;
  } else if (data !== null) {
    options.headers['Content-Type'] = 'application/json';
    options.body = JSON.stringify({ ...data, _token: window.WA_CSRF ?? '' });
  }

  const response = await fetch(url, options);
  const payload = await response.json().catch(() => ({}));

  if (!response.ok || payload.ok === false) {
    throw new Error(payload.error ?? `Die Anfrage ist fehlgeschlagen (${response.status}).`);
  }
  return payload;
}

/* ------------------------------------------------------------------ */
/* Seitenleiste auf schmalen Bildschirmen                              */
/* ------------------------------------------------------------------ */

/**
 * Ein gefundener Wert wird per Klick ins Feld uebernommen.
 *
 * Gebraucht beim Veroeffentlichen: Der Verbindungstest listet die
 * Verzeichnisse auf, die es beim Kunden wirklich gibt. Bei einer
 * Subdomain ist das der Unterschied zwischen Raten und Auswaehlen.
 */
function initFill() {
  document.querySelectorAll('[data-fill]').forEach((button) => {
    button.addEventListener('click', () => {
      const feld = document.querySelector(button.dataset.fill);
      if (!feld) return;

      feld.value = button.dataset.fillValue ?? '';
      feld.dispatchEvent(new Event('input', { bubbles: true }));
      feld.focus();
    });
  });
}

/**
 * Einen Text in die Zwischenablage legen.
 *
 * Mit Rueckfall: Die moderne Schnittstelle gibt es nur ueber HTTPS. Wer
 * das Backend ueber eine unverschluesselte Verbindung oeffnet, bekaeme
 * sonst eine Schaltflaeche, die nichts tut - und wuesste nicht, warum.
 */
function initCopy() {
  document.querySelectorAll('[data-copy]').forEach((button) => {
    const quelle = document.querySelector(button.dataset.copy);
    if (!quelle) return;

    const urText = button.textContent;

    button.addEventListener('click', async () => {
      const text = quelle.value ?? quelle.textContent ?? '';
      let geklappt = false;

      try {
        if (navigator.clipboard && window.isSecureContext) {
          await navigator.clipboard.writeText(text);
          geklappt = true;
        }
      } catch {
        geklappt = false;
      }

      if (!geklappt) {
        // Der alte Weg: markieren und kopieren lassen.
        quelle.removeAttribute('readonly');
        quelle.select();
        quelle.setSelectionRange(0, text.length);
        geklappt = document.execCommand('copy');
        quelle.setAttribute('readonly', 'readonly');
      }

      if (geklappt) {
        button.textContent = button.dataset.copyDone ?? 'Kopiert';
        button.classList.add('is-done');
        setTimeout(() => {
          button.textContent = urText;
          button.classList.remove('is-done');
        }, 2400);
        return;
      }

      // Auch das ging nicht - dann wenigstens markieren, damit der
      // Betreiber selbst kopieren kann.
      quelle.focus();
      quelle.select();
      toast('Kopieren ging nicht. Der Text ist markiert – mit Strg+C kopieren.', 'danger');
    });
  });
}

function initSidebar() {
  // Geöffnet und geschlossen wird über ein Kästchen im Markup, rein per
  // CSS. Ohne JavaScript funktioniert das Menü damit weiterhin – das ist
  // der Punkt: Eine Verwaltung, deren Navigation an einem Skript hängt,
  // ist unbedienbar, sobald das Skript einmal nicht ankommt.
  //
  // Hier kommt nur die Zugabe dazu: das Verdunkeln dahinter, Schliessen
  // per Klick daneben und mit der Escape-Taste.
  const state = document.getElementById('wa-admin-nav');
  const side = document.querySelector('.wa-admin__side');
  if (!state || !side) return;

  const scrim = document.createElement('div');
  scrim.className = 'wa-admin__scrim';
  document.body.append(scrim);

  const sync = () => {
    scrim.classList.toggle('is-open', state.checked);
  };

  const close = () => {
    state.checked = false;
    sync();
  };

  state.addEventListener('change', sync);
  scrim.addEventListener('click', close);

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') close();
  });

  // Nach einem Klick auf einen Eintrag soll die Leiste zugehen – sonst
  // steht sie beim Zurückgehen im Verlauf wieder offen da.
  side.addEventListener('click', (event) => {
    if (event.target.closest('a, button')) close();
  });

  sync();
}

/* ------------------------------------------------------------------ */
/* Meldungen                                                           */
/* ------------------------------------------------------------------ */

export function toast(message, type = 'info') {
  let holder = document.querySelector('.wa-toasts');
  if (!holder) {
    holder = document.createElement('div');
    holder.className = 'wa-toasts';
    holder.setAttribute('role', 'status');
    holder.setAttribute('aria-live', 'polite');
    document.body.append(holder);
  }

  const item = document.createElement('div');
  item.className = `wa-toast wa-toast--${type}`;
  item.textContent = message;
  holder.append(item);

  requestAnimationFrame(() => item.classList.add('is-in'));

  setTimeout(() => {
    item.classList.remove('is-in');
    setTimeout(() => item.remove(), 320);
  }, type === 'danger' ? 7000 : 4200);
}

/* ------------------------------------------------------------------ */
/* Sicherheitsabfrage vor unwiderruflichen Aktionen                    */
/* ------------------------------------------------------------------ */

function initConfirms() {
  document.addEventListener('submit', (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement)) return;

    const question = form.dataset.confirm;
    if (!question) return;

    if (!window.confirm(question)) {
      event.preventDefault();
    }
  });
}

/* ------------------------------------------------------------------ */
/* Auftragsfortschritt                                                 */
/*                                                                     */
/* Der Server arbeitet einen Auftrag in Schritten ab. Hier wird der     */
/* Stand regelmässig abgefragt und angezeigt – mit wachsendem Abstand,  */
/* damit ein langer Auftrag nicht hunderte Anfragen auslöst.           */
/* ------------------------------------------------------------------ */

function initJobWatchers() {
  document.querySelectorAll('[data-job-watch]').forEach((element) => {
    const jobId = element.dataset.jobWatch;
    if (!jobId) return;

    const bar = element.querySelector('[data-job-bar]');
    const label = element.querySelector('[data-job-label]');
    const step = element.querySelector('[data-job-step]');
    const puls = element.querySelector('[data-job-puls]');

    let delay = 1200;
    let stopped = false;

    const poll = async () => {
      if (stopped) return;

      try {
        const { job } = await api(`/api/jobs/${encodeURIComponent(jobId)}`, { method: 'GET' });

        if (bar) bar.style.setProperty('--value', `${job.progress}%`);
        if (label) label.textContent = `${job.progress}%`;
        if (step) step.textContent = job.message || job.step || '';

        // Lebt er noch?
        //
        // Ein Bau dauert je nach Umfang zwanzig Minuten und mehr, und ein
        // einzelner Aufruf an die KI zwanzig bis dreissig Sekunden. Die
        // Prozentzahl steht deshalb minutenlang still, ohne dass etwas
        // kaputt waere. Von aussen war "arbeitet gerade" nicht von
        // "haengt fest" zu unterscheiden - und wer abbricht und neu
        // anfaengt, zahlt alles noch einmal.
        if (puls) {
          const still = job.still_seit ?? 0;
          const aufrufe = job.aufrufe ?? 0;

          if (job.lebt) {
            puls.dataset.state = 'lebt';
            puls.textContent = still < 5
              ? `arbeitet gerade · ${aufrufe} Schritte erledigt`
              : `arbeitet · letzte Regung vor ${still} s · ${aufrufe} Schritte erledigt`;
          } else {
            puls.dataset.state = 'still';
            const minuten = Math.round(still / 60);
            puls.textContent = `seit ${minuten} Minuten keine Regung · ${aufrufe} Schritte erledigt`;
          }
        }

        element.dataset.jobStatus = job.status;

        if (job.status === 'done') {
          stopped = true;
          element.classList.add('is-done');
          if (job.redirect) {
            window.location.href = job.redirect;
          } else {
            window.location.reload();
          }
          return;
        }

        if (job.status === 'failed') {
          stopped = true;
          element.classList.add('is-failed');
          toast(job.error || 'Der Auftrag ist fehlgeschlagen.', 'danger');

          // Der Zwischenstand bleibt erhalten. Wer die Ursache behoben hat
          // – meist eine fehlende Einstellung –, macht dort weiter, wo es
          // geklemmt hat, statt von vorne zu beginnen.
          const resume = element.querySelector('[data-job-resume]');
          if (resume) resume.hidden = false;
          return;
        }

        // Der Server arbeitet – der Abstand wächst langsam bis 5 Sekunden.
        delay = Math.min(delay * 1.15, 5000);
      } catch (error) {
        // Netzaussetzer sind kein Grund aufzugeben, nur langsamer zu fragen.
        delay = Math.min(delay * 1.6, 15000);
      }

      setTimeout(poll, delay);
    };

    const resume = element.querySelector('[data-job-resume]');

    if (resume) {
      resume.addEventListener('click', async () => {
        resume.disabled = true;
        resume.textContent = 'Wird fortgesetzt …';

        try {
          await api(`/api/jobs/${encodeURIComponent(jobId)}/nochmal`, { method: 'POST' });
          window.location.reload();
        } catch (error) {
          toast(error.message, 'danger');
          resume.disabled = false;
          resume.textContent = 'Fortsetzen';
        }
      });
    }

    setTimeout(poll, 600);
  });
}

/* ------------------------------------------------------------------ */
/* Formulare, die abgeschickt werden, sperren                          */
/* ------------------------------------------------------------------ */

function initSubmitGuards() {
  document.addEventListener('submit', (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || event.defaultPrevented) return;

    const button = form.querySelector('button[type="submit"]');
    if (!button || button.dataset.noGuard === '1') return;

    // Kurz verzögern, sonst kommt der deaktivierte Knopf nicht mit ins Formular.
    setTimeout(() => {
      button.disabled = true;
      button.dataset.originalText = button.textContent ?? '';
      button.innerHTML = '<span class="wa-spinner"></span> Bitte warten …';
    }, 0);
  });
}


/* ------------------------------------------------------------------ */
/* Felder, die andere Felder ein- und ausblenden                       */
/*                                                                     */
/* Statt das Formular mit allem gleichzeitig zu überfrachten, erscheint */
/* Technisches erst, wenn es gebraucht wird.                           */
/* ------------------------------------------------------------------ */

function initToggles() {
  document.querySelectorAll('[data-toggles]').forEach((control) => {
    const target = document.querySelector(control.dataset.toggles);
    if (!target) return;

    const wanted = control.dataset.toggleValue ?? null;
    const notValue = control.dataset.toggleNot ?? null;

    const update = () => {
      let show;
      if (control.type === 'checkbox') {
        show = control.checked;
      } else if (wanted !== null) {
        show = control.value === wanted;
      } else if (notValue !== null) {
        show = control.value !== notValue;
      } else {
        show = Boolean(control.value);
      }
      target.hidden = !show;
    };

    control.addEventListener('change', update);
    control.addEventListener('input', update);
    update();
  });
}

/* ------------------------------------------------------------------ */
/* Farbwahl: Wähler und Hex-Feld halten sich gegenseitig aktuell       */
/* ------------------------------------------------------------------ */

function initColours() {
  document.querySelectorAll('[data-colour-for]').forEach((swatch) => {
    const hex = document.getElementById(swatch.dataset.colourFor);
    if (!hex) return;

    swatch.addEventListener('input', () => {
      hex.value = swatch.value;
      checkContrast();
    });

    hex.addEventListener('input', () => {
      let value = hex.value.trim();
      if (value && !value.startsWith('#')) value = '#' + value;
      if (/^#[0-9a-fA-F]{6}$/.test(value)) {
        swatch.value = value;
        checkContrast();
      }
    });
  });

  checkContrast();
}

/**
 * Warnt, wenn die gewählten Farben schlecht lesbar wären.
 *
 * Ein Kunde wählt oft eine Farbe, die er schön findet, ohne zu wissen,
 * dass weisse Schrift darauf kaum lesbar ist. Der Hinweis ist eine
 * Warnung, keine Sperre – die Website wird trotzdem gebaut und die
 * Textfarbe dann passend dunkler oder heller gewählt.
 */
function checkContrast() {
  const warning = document.querySelector('[data-colour-warning]');
  if (!warning) return;

  const primary = document.getElementById('color_primary')?.value ?? '';
  if (!/^#[0-9a-fA-F]{6}$/.test(primary)) {
    warning.hidden = true;
    return;
  }

  const ratio = contrastWithWhite(primary);

  if (ratio < 4.5) {
    warning.hidden = false;
    warning.textContent =
      `Hinweis: Weisse Schrift auf dieser Hauptfarbe erreicht nur ${ratio.toFixed(1)}:1 ` +
      '(nötig sind 4.5:1). Auf Schaltflächen wird deshalb dunkle Schrift verwendet.';
  } else {
    warning.hidden = true;
  }
}

function contrastWithWhite(hex) {
  const toLinear = (channel) => {
    const c = channel / 255;
    return c <= 0.04045 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
  };

  const r = parseInt(hex.slice(1, 3), 16);
  const g = parseInt(hex.slice(3, 5), 16);
  const b = parseInt(hex.slice(5, 7), 16);

  const luminance = 0.2126 * toLinear(r) + 0.7152 * toLinear(g) + 0.0722 * toLinear(b);
  return 1.05 / (luminance + 0.05);
}

/* ------------------------------------------------------------------ */
/* Hosting-Anbieter: Hilfe und Voreinstellungen umschalten             */
/* ------------------------------------------------------------------ */

function initHostingHelp() {
  const select = document.querySelector('[data-hosting-select]');
  if (!select) return;

  const apply = () => {
    const option = select.selectedOptions[0];
    if (!option) return;

    document.querySelectorAll('[data-hosting-help]').forEach((help) => {
      help.hidden = help.dataset.hostingHelp !== select.value;
    });

    // Übertragungsart, Port und Verzeichnis passend vorbelegen – der
    // Benutzer kann sie danach immer noch ändern.
    const protocol = document.getElementById('ftp_protocol');
    const port = document.getElementById('ftp_port');
    const path = document.getElementById('ftp_path');

    if (protocol && !protocol.dataset.touched) protocol.value = option.dataset.protocol ?? 'sftp';
    if (port && !port.dataset.touched) port.value = option.dataset.port ?? '22';
    if (path && !path.dataset.touched) path.value = option.dataset.path ?? '/public_html';
  };

  ['ftp_protocol', 'ftp_port', 'ftp_path'].forEach((id) => {
    document.getElementById(id)?.addEventListener('input', (event) => {
      event.target.dataset.touched = '1';
    });
  });

  select.addEventListener('change', apply);
  apply();
}

/* ------------------------------------------------------------------ */
/* Passwort erzeugen                                                   */
/* ------------------------------------------------------------------ */

function initPasswordGenerator() {
  document.querySelectorAll('[data-generate-password]').forEach((button) => {
    button.addEventListener('click', () => {
      const field = document.querySelector(button.dataset.generatePassword);
      if (!field) return;

      // Ohne verwechselbare Zeichen (0/O, 1/l/I) – das Passwort wird
      // oft abgetippt oder am Telefon durchgegeben.
      const alphabet = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789!?%+=@#';
      const values = new Uint32Array(18);
      crypto.getRandomValues(values);

      field.value = [...values].map((n) => alphabet[n % alphabet.length]).join('');
      field.dispatchEvent(new Event('input', { bubbles: true }));
      toast('Passwort erzeugt – bitte notieren.', 'success');
    });
  });
}

/* ------------------------------------------------------------------ */

function boot() {
  initSidebar();
  initFill();
  initCopy();
  initConfirms();
  initJobWatchers();
  initSubmitGuards();
  initToggles();
  initColours();
  initHostingHelp();
  initPasswordGenerator();
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', boot, { once: true });
} else {
  boot();
}
