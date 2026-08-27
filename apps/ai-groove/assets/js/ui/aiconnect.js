/**
 * AI Groove – „KI verbinden“.
 *
 * Jeder Nutzer bringt seinen eigenen Zugang mit. Der Key bleibt auf dem Gerät
 * und wird ausschliesslich als Header genau der Anfrage mitgesendet, die der
 * Nutzer selbst auslöst.
 */

import { h, icon, clear, segmented, toggle } from '../core/dom.js';
import { navigate } from './router.js';
import { toast } from './toast.js';
import { confirmModal } from './modal.js';
import { keystore, KeyStore } from '../ai/keystore.js';
import { listProviders, getProvider, checkProxy, AIError } from '../ai/providers.js';
import { generateVariants } from '../ai/generate.js';
import { isIOS } from '../core/util.js';

export function createAiConnectView() {
  const element = h('div.page');
  const inner = h('div.page__inner');
  element.appendChild(inner);

  let providerId = keystore.isConnected ? keystore.providerId : 'stability';
  let mode = keystore.mode;
  let transport = keystore.transport;

  const keyInput = h('input.input.input--mono', {
    type: 'password',
    placeholder: 'API-Key hier einfügen',
    autocomplete: 'off',
    autocapitalize: 'off',
    autocorrect: 'off',
    spellcheck: false,
  });

  const statusBox = h('div.settings-group');
  const providerBox = h('div.settings-group');

  function providerInfo() {
    return getProvider(providerId);
  }

  function renderStatus() {
    clear(statusBox);
    statusBox.appendChild(h('h2.section-title', { text: 'Status' }));

    if (keystore.isConnected) {
      const provider = getProvider(keystore.providerId);
      statusBox.appendChild(
        h('div.settings-row', null, [
          h('div.settings-row__label', null, [
            h('b', { text: `Verbunden mit ${provider.label}` }),
            h('span', { text: `Key: ${keystore.maskedKey()} · Speicherung: ${keystore.mode === 'session' ? 'nur diese Sitzung' : 'lokal auf diesem Gerät'} · Übertragung: ${keystore.transport === 'direct' ? 'direkt zum Anbieter' : 'über deinen eigenen Server'}` }),
          ]),
          h('div.settings-row__control', null, [
            (() => {
              const btn = h('button.btn.btn--sm.btn--danger', { type: 'button', text: 'Verbindung entfernen' });
              btn.addEventListener('click', async () => {
                const ok = await confirmModal({
                  title: 'Verbindung entfernen?',
                  message: 'Der API-Key wird vollständig von diesem Gerät gelöscht.',
                  confirmLabel: 'Entfernen',
                  danger: true,
                });
                if (ok) {
                  keystore.clear();
                  keyInput.value = '';
                  renderStatus();
                  toast.ok('Verbindung entfernt', 'Der Key wurde gelöscht.');
                }
              });
              return btn;
            })(),
          ]),
        ]),
      );
    } else {
      statusBox.appendChild(
        h('div.settings-row', null, [
          h('div.settings-row__label', null, [
            h('b', { text: 'Kein eigener KI-Anbieter verbunden' }),
            h('span', {
              text: 'AI Groove funktioniert trotzdem vollständig: der eingebaute lokale Synth-Generator erzeugt Drums, Bass, Stabs, Riser und FX ohne Internet und ohne Kosten.',
            }),
          ]),
        ]),
      );
    }
  }

  function renderProviderBox() {
    clear(providerBox);
    providerBox.appendChild(h('h2.section-title', { text: 'Anbieter wählen' }));

    const seg = segmented(
      listProviders().map((p) => ({ label: p.label.split('—')[0].trim(), value: p.id })),
      providerId,
      (value) => {
        providerId = value;
        renderProviderBox();
      },
    );
    providerBox.appendChild(seg);

    const provider = providerInfo();
    providerBox.appendChild(
      h('p.field__hint', {
        text: `${provider.description} Fähigkeiten: Text→Audio${provider.capabilities.audioToAudio ? ', Audio→Audio (bestehende Samples verändern)' : ''}, max. ${provider.capabilities.maxDuration} s pro Erzeugung.`,
      }),
    );

    if (!provider.needsKey) {
      const useBtn = h('button.btn.btn--primary', { type: 'button', text: 'Lokalen Generator verwenden' });
      useBtn.addEventListener('click', () => {
        keystore.clear();
        renderStatus();
        toast.ok('Lokaler Generator aktiv', 'Es wird kein API-Key benötigt.');
      });
      providerBox.appendChild(useBtn);
      return;
    }

    if (provider.website) {
      const link = h('p.field__hint');
      link.appendChild(document.createTextNode('Key erstellen: '));
      const a = h('a', { href: provider.website, target: '_blank', rel: 'noopener noreferrer', text: provider.website });
      link.appendChild(a);
      providerBox.appendChild(link);
    }

    providerBox.appendChild(
      h('div.field', null, [h('label.field__label', { text: 'Dein API-Key' }), keyInput]),
    );

    const showToggle = toggle('Key im Klartext anzeigen', false, (v) => {
      keyInput.type = v ? 'text' : 'password';
    });
    providerBox.appendChild(showToggle);

    const modeSeg = segmented(
      [
        { label: 'Auf diesem Gerät speichern', value: 'local' },
        { label: 'Nur für diese Sitzung', value: 'session' },
      ],
      mode,
      (value) => {
        mode = value;
      },
    );
    providerBox.appendChild(
      h('div.field', null, [h('label.field__label', { text: 'Speicherung' }), modeSeg]),
    );

    const transportSeg = segmented(
      [
        { label: 'Über eigenen Server (empfohlen)', value: 'proxy' },
        { label: 'Direkt aus dem Browser', value: 'direct' },
      ],
      transport,
      (value) => {
        transport = value;
      },
    );
    providerBox.appendChild(
      h('div.field', null, [
        h('label.field__label', { text: 'Übertragungsweg' }),
        transportSeg,
        h('p.field__hint', {
          text: 'Die meisten Anbieter blockieren direkte Browser-Aufrufe (CORS). Der mitgelieferte PHP-Proxy leitet die Anfrage weiter und speichert oder protokolliert den Key dabei nicht.',
        }),
      ]),
    );

    const saveBtn = h('button.btn.btn--primary', { type: 'button' });
    saveBtn.appendChild(icon('check', 16));
    saveBtn.appendChild(h('span', { text: 'Verbinden' }));
    saveBtn.addEventListener('click', () => {
      const key = keyInput.value.trim();
      const problem = KeyStore.validateKey(providerId, key);
      if (problem) {
        toast.warn('Key prüfen', problem);
        return;
      }
      keystore.save({ providerId, key, mode, transport });
      keyInput.value = '';
      renderStatus();
      toast.ok('Verbunden', `${providerInfo().label} ist eingerichtet.`);
    });

    const testBtn = h('button.btn', { type: 'button', text: 'Verbindung testen' });
    testBtn.addEventListener('click', async () => {
      if (!keystore.isConnected) {
        toast.warn('Noch nicht verbunden', 'Bitte zuerst den Key speichern.');
        return;
      }
      const stop = toast.sticky('Verbindung wird getestet …');
      try {
        await generateVariants({
          prompt: 'short soft test blip, very quiet',
          duration: 1,
          count: 1,
        });
        stop();
        toast.ok('Verbindung funktioniert', 'Der Anbieter hat geantwortet.');
      } catch (err) {
        stop();
        if (err instanceof AIError) toast.error('Test fehlgeschlagen', `${err.message}${err.detail ? ` (${err.detail})` : ''}`);
        else toast.error('Test fehlgeschlagen', err.message || 'Unbekannter Fehler');
      }
    });

    providerBox.appendChild(h('div.sed__tools', null, [saveBtn, testBtn]));
  }

  function build() {
    clear(inner);

    const head = h('div.page-head');
    const backBtn = h('button.btn.btn--ghost.btn--sm', { type: 'button' });
    const backIcon = icon('chevron', 16);
    backIcon.style.transform = 'rotate(180deg)';
    backBtn.appendChild(backIcon);
    backBtn.appendChild(h('span', { text: 'Zurück' }));
    backBtn.addEventListener('click', () => history.back());
    head.appendChild(backBtn);
    head.appendChild(h('h1', { text: 'KI verbinden', style: { fontSize: '22px' } }));
    inner.appendChild(head);

    inner.appendChild(statusBox);
    inner.appendChild(providerBox);
    renderStatus();
    renderProviderBox();

    // --- Datenschutz -------------------------------------------------------
    const privacy = h('div.settings-group');
    privacy.appendChild(h('h2.section-title', { text: 'Was mit deinem Key passiert' }));
    const list = h('ul.prose');
    for (const line of [
      'Der Key wird ausschliesslich auf diesem Gerät gespeichert (localStorage bzw. nur für die Sitzung).',
      'Er wird niemals auf dem Server gespeichert und nicht protokolliert.',
      'Er landet niemals in einer exportierten .aigroove-Projektdatei.',
      'Er wird nicht an andere Nutzer weitergegeben – jeder verbindet seinen eigenen Zugang.',
      'Beim Weg „über eigenen Server“ reicht der PHP-Proxy den Key nur für diese eine Anfrage an den Anbieter weiter und verwirft ihn danach.',
      'Fehlermeldungen werden bereinigt, damit kein Key sichtbar wird.',
      'Über „Verbindung entfernen“ wird der Key vollständig gelöscht.',
    ]) {
      list.appendChild(h('li', { text: line }));
    }
    privacy.appendChild(list);
    inner.appendChild(privacy);

    // --- Proxy-Status ------------------------------------------------------
    const proxyBox = h('div.settings-group');
    proxyBox.appendChild(h('h2.section-title', { text: 'Server-Proxy' }));
    const proxyLine = h('p.field__hint', { text: 'Wird geprüft …' });
    proxyBox.appendChild(proxyLine);
    inner.appendChild(proxyBox);

    checkProxy().then((result) => {
      proxyLine.textContent = result.ok
        ? `Der Proxy ist erreichbar (PHP ${result.php}${result.curl ? ', cURL verfügbar' : ', ohne cURL – Fallback aktiv'}).`
        : `${result.message} Prüfe, ob der Ordner „api“ mit auf den Server hochgeladen wurde und PHP 8.1 oder neuer aktiv ist.`;
    });

    // --- Claude Code -------------------------------------------------------
    const claudeBox = h('div.settings-group');
    claudeBox.appendChild(h('h2.section-title', { text: 'Claude Code (optional)' }));
    claudeBox.appendChild(
      h('p.prose', {
        text: 'Claude Code wird für AI Groove nicht benötigt. Für die Audio-Erzeugung gibt es keine offizielle Claude-Code-Schnittstelle – AI Groove kann und will dafür kein Claude-Kontingent verwenden. Wer AI Groove selbst weiterentwickeln möchte, kann Claude Code hier öffnen:',
      }),
    );
    const claudeRow = h('div.sed__tools');
    const claudeApp = h('a.btn.btn--sm', { href: 'claude://code', text: 'Claude Code öffnen (App)' });
    const claudeWeb = h('a.btn.btn--sm', {
      href: 'https://claude.ai/code',
      target: '_blank',
      rel: 'noopener noreferrer',
      text: 'Claude Code im Browser',
    });
    claudeRow.appendChild(claudeApp);
    claudeRow.appendChild(claudeWeb);
    claudeBox.appendChild(claudeRow);
    claudeBox.appendChild(
      h('p.field__hint', {
        text: isIOS
          ? 'Auf dem iPhone öffnet der erste Link die Claude-App, sofern sie installiert ist. Andernfalls passiert nichts – dann den Browser-Link verwenden.'
          : 'Der App-Link funktioniert nur, wenn die Claude-App auf diesem Gerät installiert ist.',
      }),
    );
    claudeBox.appendChild(
      h('p.field__hint', {
        text: 'AI Groove fragt niemals nach Claude-Zugangsdaten und speichert keine.',
      }),
    );
    inner.appendChild(claudeBox);

    const backHome = h('button.btn.btn--sm', { type: 'button', text: 'Zum Studio' });
    backHome.addEventListener('click', () => navigate('/studio'));
    inner.appendChild(backHome);
  }

  return {
    element,
    onShow() {
      providerId = keystore.isConnected ? keystore.providerId : providerId;
      mode = keystore.mode;
      transport = keystore.transport;
      build();
    },
  };
}
