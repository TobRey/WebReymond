/**
 * AI Groove – Assistent „Groove AI“ (Oberfläche).
 *
 * Der Assistent ist optional. Er zeigt immer zuerst, was er tun würde;
 * ausgeführt wird erst nach Bestätigung. Jede Aktion ist über Undo umkehrbar.
 */

import { h, icon, clear } from '../core/dom.js';
import { planFromText, HELP_TEXT } from '../ai/assistant.js';
import { executePlan } from '../ai/actions.js';
import { toast, reportError } from './toast.js';
import { bus, EV } from '../core/bus.js';

const QUICK_PROMPTS = [
  'Mach mir einen 150 BPM Hard-Techno-Grundbeat',
  'Kick auf jedes Viertel',
  'Hi-Hats auf die Offbeats',
  'Mach die Hats schneller',
  'Entferne jede zweite Kick',
  'Alle 4 Takte ein Clap Fill',
  'Der Bass soll erst nach 16 Takten kommen',
  'Mach den Drop härter',
];

export function createChatPanel() {
  const element = h('div.chat');

  const log = h('div.chat__log');
  element.appendChild(log);

  const chips = h('div.chat__chips');
  for (const prompt of QUICK_PROMPTS) {
    const chip = h('button.chip', { type: 'button', text: prompt });
    chip.addEventListener('click', () => {
      input.value = prompt;
      send();
    });
    chips.appendChild(chip);
  }
  element.appendChild(chips);

  const inputRow = h('div.chat__input');
  const input = h('textarea.textarea', {
    placeholder: 'Was soll ich bauen? z. B. „Mach mir einen 155 BPM Hard-Techno-Grundbeat“',
    rows: 1,
    maxLength: 400,
  });
  const sendBtn = h('button.btn.btn--primary.btn--icon', { type: 'button', 'aria-label': 'Senden' });
  sendBtn.appendChild(icon('chevron', 17));
  sendBtn.addEventListener('click', () => send());
  inputRow.appendChild(input);
  inputRow.appendChild(sendBtn);
  element.appendChild(inputRow);

  input.addEventListener('keydown', (event) => {
    if (event.key === 'Enter' && !event.shiftKey) {
      event.preventDefault();
      send();
    }
  });
  input.addEventListener('input', () => {
    input.style.height = 'auto';
    input.style.height = `${Math.min(120, input.scrollHeight)}px`;
  });

  function addMessage(kind, text, extras) {
    const msg = h(`div.msg.msg--${kind}`, { text });
    if (extras) msg.appendChild(extras);
    log.appendChild(msg);
    log.scrollTop = log.scrollHeight;
    return msg;
  }

  function stepList(steps) {
    const wrap = h('div.msg__actions');
    for (const step of steps) wrap.appendChild(h('div', { text: `• ${step}` }));
    return wrap;
  }

  async function run(plan) {
    const busy = addMessage('ai', 'Wird ausgeführt …');
    try {
      const { applied, errors } = await executePlan(plan.actions, {
        onStep: (label) => {
          busy.textContent = `${label} …`;
        },
      });
      busy.remove();

      if (applied.length) {
        addMessage('ai', `Erledigt (${applied.length} Schritt(e)).`, stepList(applied));
        toast.ok('Groove AI', `${applied.length} Änderung(en) übernommen`);
      }
      if (errors.length) {
        addMessage('err', 'Nicht alles hat geklappt:', stepList(errors));
      }
      if (!applied.length && !errors.length) {
        addMessage('ai', 'Es gab nichts zu tun.');
      }
    } catch (err) {
      busy.remove();
      reportError('Groove AI', err);
      addMessage('err', 'Das hat nicht funktioniert. Details stehen in der Entwicklerkonsole.');
    }
  }

  function send() {
    const text = input.value.trim();
    if (!text) return;
    input.value = '';
    input.style.height = 'auto';
    addMessage('user', text);

    const plan = planFromText(text);
    if (!plan.actions.length) {
      addMessage('ai', plan.reply);
      return;
    }

    if (!plan.needsConfirm) {
      addMessage('ai', plan.reply, stepList(plan.steps));
      run(plan);
      return;
    }

    const msg = addMessage('ai', plan.reply, stepList(plan.steps));
    const actions = h('div.sed__tools', { style: { marginTop: '10px' } });
    const okBtn = h('button.btn.btn--sm.btn--primary', { type: 'button', text: 'Ausführen' });
    const noBtn = h('button.btn.btn--sm.btn--ghost', { type: 'button', text: 'Verwerfen' });
    okBtn.addEventListener('click', () => {
      actions.remove();
      run(plan);
    });
    noBtn.addEventListener('click', () => {
      actions.remove();
      addMessage('ai', 'Alles klar, ich ändere nichts.');
    });
    actions.appendChild(okBtn);
    actions.appendChild(noBtn);
    msg.appendChild(actions);
    log.scrollTop = log.scrollHeight;
  }

  function greet() {
    clear(log);
    addMessage(
      'ai',
      `Hallo! Ich bin Groove AI und kann dein Projekt direkt bearbeiten.\n\n${HELP_TEXT}`,
    );
  }

  const offLoaded = bus.on(EV.PROJECT_LOADED, greet);
  greet();

  return {
    element,
    focus: () => input.focus(),
    ask: (text) => {
      input.value = text;
      send();
    },
    destroy() {
      offLoaded();
    },
  };
}
