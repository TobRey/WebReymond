/*
 * REYMOND TOBIAS – Kontaktformular.
 *
 * Zwei Betriebsarten:
 *   1. Ohne Server (Standard): die Anfrage wird im Mailprogramm des
 *      Besuchers geöffnet, fertig ausgefüllt. Nichts zu konfigurieren.
 *   2. Mit Formulardienst: im HTML data-endpoint="https://…" setzen.
 *      Dann wird die Anfrage per fetch verschickt, ohne Seitenwechsel.
 */

(function () {
  'use strict';

  var form = document.querySelector('[data-contact-form]');
  if (!form) return;

  var status = form.querySelector('[data-form-status]');
  var MAIL_TO = 'booking@reymond-tobias.ch';

  function say(text) {
    if (status) status.textContent = text;
  }

  function value(name) {
    var field = form.elements[name];
    return field ? String(field.value).trim() : '';
  }

  function validate() {
    var name = value('name');
    var email = value('email');

    if (name.length < 2) {
      say('Bitte Namen eintragen.');
      return false;
    }

    // Bewusst grosszügig geprüft: der Versand entscheidet, nicht die Seite.
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(email)) {
      say('Bitte gültige E-Mail-Adresse eintragen.');
      return false;
    }

    if (!value('anlass')) {
      say('Bitte Anlass auswählen.');
      return false;
    }

    return true;
  }

  function buildText() {
    return [
      'Name: ' + value('name'),
      'E-Mail: ' + value('email'),
      'Anlass: ' + value('anlass'),
      'Datum: ' + (value('datum') || '–'),
      'Ort: ' + (value('ort') || '–'),
      '',
      value('nachricht') || '(keine Nachricht)',
    ].join('\n');
  }

  function sendByMail() {
    var subject = 'Booking-Anfrage: ' + value('anlass') + ' – ' + value('name');
    var href =
      'mailto:' +
      MAIL_TO +
      '?subject=' +
      window.encodeURIComponent(subject) +
      '&body=' +
      window.encodeURIComponent(buildText());

    window.location.href = href;
    say('Mailprogramm geöffnet – Anfrage nur noch abschicken.');
  }

  function sendToEndpoint(endpoint) {
    say('Wird gesendet …');

    window
      .fetch(endpoint, {
        method: 'POST',
        headers: { Accept: 'application/json' },
        body: new window.FormData(form),
      })
      .then(function (response) {
        if (!response.ok) throw new Error('Antwort ' + response.status);
        form.reset();
        say('Danke – die Anfrage ist unterwegs.');
      })
      .catch(function () {
        say('Versand fehlgeschlagen. Bitte direkt an ' + MAIL_TO + ' schreiben.');
      });
  }

  form.addEventListener('submit', function (event) {
    event.preventDefault();

    // Spam-Falle: nur Automaten füllen dieses Feld aus.
    if (value('website')) return;

    if (!validate()) return;

    var endpoint = form.getAttribute('data-endpoint');
    if (endpoint) {
      sendToEndpoint(endpoint);
    } else {
      sendByMail();
    }
  });

  // Sobald jemand tippt, verschwindet die alte Meldung.
  form.addEventListener('input', function () {
    say('');
  });
})();
