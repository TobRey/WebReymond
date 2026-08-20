/*
 * REYMOND TOBIAS – Kontaktformular (WordPress).
 *
 * Der Versand läuft serverseitig über admin-post.php. Dieses Skript prüft
 * die Eingaben nur vorab, damit niemand die Seite für einen Tippfehler neu
 * laden muss. Ohne JavaScript funktioniert das Formular unverändert – dann
 * prüft WordPress die Angaben und meldet das Ergebnis zurück.
 */

(function () {
  'use strict';

  var form = document.querySelector('[data-contact-form]');
  if (!form) return;

  var status = form.querySelector('[data-form-status]');

  function say(text) {
    if (status) status.textContent = text;
  }

  function value(name) {
    var field = form.elements[name];
    return field ? String(field.value).trim() : '';
  }

  form.addEventListener('submit', function (event) {
    if (value('name').length < 2) {
      event.preventDefault();
      say('Bitte Namen eintragen.');
      return;
    }

    // Bewusst grosszügig geprüft: der Server entscheidet, nicht die Seite.
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(value('email'))) {
      event.preventDefault();
      say('Bitte gültige E-Mail-Adresse eintragen.');
      return;
    }

    if (!value('anlass')) {
      event.preventDefault();
      say('Bitte Anlass auswählen.');
      return;
    }

    say('Wird gesendet …');
  });

  // Sobald jemand tippt, verschwindet die alte Meldung.
  form.addEventListener('input', function () {
    say('');
  });
})();
