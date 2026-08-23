/* ============================================================
   Dice Duel - Client
   Rendering ist bewusst diff-basiert: pro Poll wird nur das
   angefasst, was sich wirklich geändert hat.
   ============================================================ */
(() => {
  'use strict';

  const CATALOG = window.CATALOG || { dice: {}, potions: {} };
  const $ = (id) => document.getElementById(id);
  const el = (tag, cls, text) => {
    const node = document.createElement(tag);
    if (cls) node.className = cls;
    if (text != null) node.textContent = text;
    return node;
  };

  const STORAGE_KEY = 'diceduel.session';
  const dieType = (id) => (id.includes('#') ? id.slice(0, id.indexOf('#')) : id);

  /* -------------------------------------------------------- Zustand */
  const session = { code: '', token: '', slot: 0 };
  let state = null;          // letzter gerenderter Serverzustand
  let queued = null;         // Zustand, der nach der Wurfanimation greift
  let animating = false;
  let seenRollSeq = 0;
  let polling = false;
  let selectedDice = [];
  let shopTab = 'dice';
  const sig = { targets: '', targetsBoard: '', tray: '', log: -1, logLast: '', shop: '' };

  /* ---------------------------------------------------------- Toasts */
  function toast(message, kind) {
    const node = el('div', 'toast' + (kind ? ' is-' + kind : ''), message);
    $('toasts').appendChild(node);
    setTimeout(() => {
      node.classList.add('is-out');
      setTimeout(() => node.remove(), 220);
    }, 2400);
  }

  /* ------------------------------------------------------------- API */
  async function api(action, body = {}) {
    const res = await fetch('api.php?action=' + action, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(Object.assign({ action }, body)),
    });
    let data;
    try {
      data = await res.json();
    } catch {
      throw new Error('Serverantwort konnte nicht gelesen werden.');
    }
    if (!data.ok && data.error && !data.state) throw new Error(data.error);
    return data;
  }

  /* --------------------------------------------------------- Screens */
  function showScreen(id) {
    document.querySelectorAll('.screen').forEach((s) => s.classList.toggle('is-active', s.id === id));
  }

  /* ---------------------------------------------------------- Lobby  */
  function saveSession() {
    try {
      localStorage.setItem(STORAGE_KEY, JSON.stringify(session));
    } catch { /* privater Modus - egal */ }
  }
  function clearSession() {
    try { localStorage.removeItem(STORAGE_KEY); } catch { /* ignore */ }
  }

  async function createRoom() {
    const name = $('input-name').value.trim();
    if (!name) return showFormError('Bitte gib zuerst deinen Namen ein.');
    setHomeBusy(true);
    try {
      const data = await api('create', { name });
      enterRoom(data);
    } catch (err) {
      showFormError(err.message);
    } finally {
      setHomeBusy(false);
    }
  }

  async function joinRoom() {
    const name = $('input-name').value.trim();
    const code = $('input-code').value.trim().toUpperCase();
    if (!name) return showFormError('Bitte gib zuerst deinen Namen ein.');
    if (code.length < 4) return showFormError('Bitte gib einen gültigen Raumcode ein.');
    setHomeBusy(true);
    try {
      const data = await api('join', { name, code });
      enterRoom(data);
    } catch (err) {
      showFormError(err.message);
    } finally {
      setHomeBusy(false);
    }
  }

  function setHomeBusy(busy) {
    $('btn-create').disabled = busy;
    $('btn-join').disabled = busy;
  }
  function showFormError(msg) {
    $('home-error').textContent = msg || '';
  }

  function enterRoom(data) {
    session.code = data.code;
    session.token = data.token;
    session.slot = data.slot;
    saveSession();
    showFormError('');
    applyState(data.state);
    startPolling();
  }

  /* --------------------------------------------------------- Polling */
  function startPolling() {
    if (polling) return;
    polling = true;
    loop();
  }

  async function loop() {
    while (polling) {
      try {
        const version = state ? state.version : 0;
        const data = await api('state', { code: session.code, token: session.token, v: version, wait: '1' });
        if (data.state) receive(data.state);
      } catch (err) {
        if (String(err.message).includes('Token') || String(err.message).includes('nicht gefunden')) {
          polling = false;
          clearSession();
          toast('Verbindung zum Raum verloren.', 'error');
          showScreen('screen-home');
          return;
        }
        await new Promise((r) => setTimeout(r, 1500));
      }
    }
  }

  /* -------------------------------------------- Zustand übernehmen */
  function receive(next) {
    // Waehrend einer laufenden Wurfanimation nur zwischenspeichern -
    // so ueberlagern sich nie zwei Animationen.
    if (animating) { queued = next; return; }
    const roll = next.lastRoll;
    if (roll && roll.seq > seenRollSeq) {
      seenRollSeq = roll.seq;
      playRoll(roll, next);
      return;
    }
    applyState(next);
  }

  function applyState(next) {
    const prev = state;
    state = next;

    if (next.status === 'lobby') {
      renderLobby(next);
      showScreen('screen-lobby');
      return;
    }

    if (prev && prev.status === 'lobby' && next.status === 'playing') {
      toast('Beide Spieler bereit - los geht\'s!', 'good');
    }

    if (next.status === 'finished') {
      renderGame(next, prev);
      renderResult(next);
      showScreen('screen-result');
      return;
    }

    renderGame(next, prev);
    showScreen('screen-game');
  }

  /** Nach einem Reload den letzten Wurf statisch nachzeichnen - ohne Animation. */
  function restoreRoll(s) {
    if (animating) return;
    const stage = $('dice-view');
    if (!s.lastRoll) {
      // Neue Runde: Buehne leeren.
      if (stage.childElementCount) stage.textContent = '';
      $('roll-result').hidden = true;
      return;
    }
    if (stage.childElementCount > 0) return;
    s.lastRoll.dice.forEach((die) => {
      const wrap = el('div', 'cube-wrap');
      wrap.appendChild(el('div', 'cube' + (die.type !== 'basic' ? ' cube--special' : ''), String(die.value)));
      if (die.note) wrap.appendChild(el('span', 'cube__note', die.note));
      stage.appendChild(wrap);
    });
    showRollResult(s.lastRoll, true);
  }

  /* ---------------------------------------------------------- Lobby */
  function renderLobby(s) {
    $('room-code-text').textContent = s.code;
    $('lobby-p1').textContent = s.players[0] ? s.players[0].name : '-';
    const second = s.players[1];
    $('lobby-p2').textContent = second ? second.name : 'Warte auf Spieler 2';
    $('lobby-slot2').classList.toggle('is-ready', !!second);
  }

  /* ----------------------------------------------------------- Game */
  function renderGame(s, prev) {
    $('topbar-code-text').textContent = s.code;
    $('turn-counter').textContent = String(Math.min(s.turnIndex + 1, s.totalTurns));

    renderPlayers(s, prev);
    renderTargets(s);
    renderTray(s);
    renderPhase(s, prev);
    renderLog(s);
    restoreRoll(s);
    if (isShopOpen()) renderShop(s);
  }

  function renderPlayers(s, prev) {
    const host = $('players');
    if (host.childElementCount !== s.players.length) {
      host.textContent = '';
      s.players.forEach((p) => host.appendChild(buildPlayerCard(p)));
    }
    s.players.forEach((p, i) => {
      const card = host.children[i];
      const before = prev && prev.players[i];
      card.classList.toggle('is-active', s.currentSlot === p.slot);
      card.classList.toggle('is-you', p.slot === s.you);

      const nameNode = card.querySelector('.pcard__name span');
      if (nameNode.textContent !== p.name) nameNode.textContent = p.name;

      setStat(card.querySelector('[data-stat="score"]'), p.score, before && before.score);
      setStat(card.querySelector('[data-stat="money"]'), p.money, before && before.money);

      const streak = card.querySelector('.streak');
      streak.classList.toggle('is-on', p.streak > 1);
      streak.classList.toggle('is-hot', p.streak >= 3);
      if (p.streak > 1) streak.textContent = '🔥 Streak x' + p.streak;

      const potionHost = card.querySelector('.pcard__potions');
      const key = p.potions.join(',');
      if (potionHost.dataset.key !== key) {
        potionHost.dataset.key = key;
        potionHost.textContent = '';
        p.potions.forEach((id) => {
          const meta = CATALOG.potions[id];
          if (!meta) return;
          const chip = el('span', 'potion-chip', meta.name.replace(' Potion', ''));
          chip.title = meta.desc;
          potionHost.appendChild(chip);
        });
      }

      const dc = card.querySelector('.pcard__dicecount');
      const label = p.dice.length + ' Würfel · ' + p.turnsPlayed + '/' + s.turnsPerPlayer + ' Züge · ' + p.perfects + ' Perfect';
      if (dc.textContent !== label) dc.textContent = label;
    });
  }

  function buildPlayerCard(p) {
    const card = el('div', 'pcard');
    const top = el('div', 'pcard__top');
    const name = el('div', 'pcard__name');
    name.appendChild(el('span', null, p.name));
    top.appendChild(name);
    top.appendChild(el('span', 'pcard__turnbadge', 'Am Zug'));
    card.appendChild(top);

    const stats = el('div', 'pcard__stats');
    stats.appendChild(buildStat('Punkte', 'score', ''));
    stats.appendChild(buildStat('Geld', 'money', 'is-money'));
    const streak = el('span', 'streak', '');
    stats.appendChild(streak);
    card.appendChild(stats);

    card.appendChild(el('div', 'pcard__potions'));
    card.appendChild(el('div', 'pcard__dicecount'));
    return card;
  }

  function buildStat(label, key, extra) {
    const wrap = el('div', 'stat');
    wrap.appendChild(el('span', 'stat__label', label));
    const value = el('span', 'stat__value ' + (extra || ''), '0');
    value.dataset.stat = key;
    wrap.appendChild(value);
    return wrap;
  }

  function setStat(node, value, before) {
    const text = String(value);
    if (node.textContent === text) return;
    node.textContent = text;
    if (before != null && value !== before) {
      node.classList.remove('stat--bump');
      void node.offsetWidth;
      node.classList.add('stat--bump');
    }
  }

  /* -------------------------------------------------------- Targets */
  function renderTargets(s) {
    const host = $('targets');
    const board = s.targets.map((t) => t.value + ':' + (t.enchanted ? 1 : 0)).join(',');
    const signature = board + '#' + s.targets.map((t) => t.takenBy).join(',')
      + '#' + s.you + '#' + s.currentSlot + '#' + s.phase;
    if (sig.targets === signature) return;
    const fresh = sig.targetsBoard !== board;
    sig.targets = signature;
    sig.targetsBoard = board;

    host.textContent = '';
    s.targets.forEach((t, index) => {
      const node = el('button', 'target');
      if (!fresh) node.style.animation = 'none';
      node.appendChild(el('span', 'target__value', String(t.value)));

      const mine = t.takenBy === s.you;
      const selectable = s.currentSlot === s.you && s.phase === 'select' && t.takenBy === null;

      let tag = t.enchanted ? 'Verzaubert' : '';
      if (t.takenBy !== null) {
        tag = mine ? (t.enchanted ? 'Dein Ziel ✦' : 'Dein Ziel') : 'Vergeben';
        node.classList.add(mine ? 'is-picked' : 'is-locked');
      }
      if (t.enchanted) node.classList.add('is-enchanted');
      if (selectable) node.classList.add('is-selectable');
      node.disabled = !selectable;
      if (tag) node.appendChild(el('span', 'target__tag', tag));

      node.addEventListener('click', () => pickTarget(index, node));
      host.appendChild(node);
    });
  }

  async function pickTarget(index, node) {
    if (!state || state.currentSlot !== state.you || state.phase !== 'select') return;
    node.classList.add('is-picked');

    // Optimistisch umschalten, damit die Würfelauswahl ohne Wartezeit reagiert.
    state.targets[index].takenBy = state.you;
    state.phase = 'roll';
    renderTargets(state);
    renderTray(state);
    renderPhase(state, null);

    try {
      const data = await api('select', { code: session.code, token: session.token, index });
      if (data.error) toast(data.error, 'error');
      if (data.state) receive(data.state);
    } catch (err) {
      toast(err.message, 'error');
    }
  }

  /* ------------------------------------------------------ Dice tray */
  function renderTray(s) {
    const me = s.players[s.you];
    if (!me) return;
    const myTurn = s.currentSlot === s.you && s.phase === 'roll';
    selectedDice = selectedDice.filter((id) => me.dice.includes(id));

    // Button- und Hinweiszustand immer aktualisieren, auch wenn die Leiste gleich bleibt.
    let hint;
    if (myTurn) hint = '- ' + selectedDice.length + '/2 gewählt';
    else if (s.currentSlot === s.you) hint = '- zuerst Zielzahl wählen';
    else hint = '- warte auf deinen Zug';
    $('dice-hint').textContent = hint;
    $('btn-roll').disabled = !myTurn || selectedDice.length < 1;

    const signature = me.dice.join(',') + '|' + selectedDice.join(',') + '|' + myTurn;
    if (sig.tray === signature) return;
    sig.tray = signature;

    const host = $('dice-tray');
    host.textContent = '';
    me.dice.forEach((id) => {
      const meta = CATALOG.dice[dieType(id)];
      if (!meta) return;
      const btn = el('button', 'die-btn');
      btn.appendChild(el('span', 'die-btn__icon', meta.icon));
      btn.appendChild(el('span', 'die-btn__name', meta.name));
      btn.title = meta.desc;
      const order = selectedDice.indexOf(id);
      if (order >= 0) {
        btn.classList.add('is-selected');
        btn.appendChild(el('span', 'die-btn__order', String(order + 1)));
      }
      btn.disabled = !myTurn;
      btn.addEventListener('click', () => toggleDie(id));
      host.appendChild(btn);
    });
  }

  function toggleDie(id) {
    const at = selectedDice.indexOf(id);
    if (at >= 0) selectedDice.splice(at, 1);
    else if (selectedDice.length < 2) selectedDice.push(id);
    else selectedDice = [selectedDice[1], id];
    sig.tray = '';
    if (state) renderTray(state);
  }

  async function doRoll() {
    if (!state || state.currentSlot !== state.you || state.phase !== 'roll') return;
    if (selectedDice.length < 1) return;
    $('btn-roll').disabled = true;
    try {
      const data = await api('roll', { code: session.code, token: session.token, dice: selectedDice });
      if (data.error) { toast(data.error, 'error'); $('btn-roll').disabled = false; }
      if (data.state) receive(data.state);
    } catch (err) {
      toast(err.message, 'error');
      $('btn-roll').disabled = false;
    }
  }

  /* ---------------------------------------------------------- Phase */
  function renderPhase(s, prev) {
    const myTurn = s.currentSlot === s.you;
    const other = s.players[1 - s.you];
    const badge = $('phase-badge');
    const owner = $('turn-owner');

    const phaseText = s.phase === 'select' ? 'Zielzahl wählen' : 'Würfel wählen & werfen';
    if (badge.textContent !== phaseText) badge.textContent = phaseText;

    const ownerText = myTurn ? 'Du bist am Zug' : (other ? other.name + ' ist am Zug' : '');
    if (owner.textContent !== ownerText) {
      owner.textContent = ownerText;
      owner.classList.remove('is-switch');
      void owner.offsetWidth;
      owner.classList.add('is-switch');
    }
    owner.classList.toggle('is-you', myTurn);

    if (prev && prev.currentSlot !== s.currentSlot && myTurn && s.status === 'playing') {
      toast('Du bist dran', 'good');
    }
  }

  /* ------------------------------------------------------------ Log */
  function renderLog(s) {
    if (sig.log === s.log.length && sig.logLast === (s.log[s.log.length - 1] || {}).text) return;
    sig.log = s.log.length;
    sig.logLast = (s.log[s.log.length - 1] || {}).text;
    const host = $('log');
    host.textContent = '';
    s.log.slice().reverse().forEach((entry) => {
      const row = el('div', 'log__row' + (entry.slot != null ? ' is-p' + entry.slot : ''));
      const parts = entry.text.split(':');
      if (entry.slot != null && parts.length > 1) {
        row.appendChild(el('b', null, parts.shift()));
        row.appendChild(document.createTextNode(':' + parts.join(':')));
      } else {
        row.textContent = entry.text;
      }
      host.appendChild(row);
    });
    host.scrollTop = 0;
  }

  /* ------------------------------------------------- Wurfanimation */
  function playRoll(roll, next) {
    // next = Zustand, der nach der Animation aktiv wird.
    animating = true;
    const stage = $('dice-view');
    const result = $('roll-result');
    result.hidden = true;
    stage.textContent = '';

    const cubes = roll.dice.map((die) => {
      const wrap = el('div', 'cube-wrap');
      const cube = el('div', 'cube' + (die.type !== 'basic' ? ' cube--special' : ''), '?');
      cube.classList.add('is-rolling');
      wrap.appendChild(cube);
      stage.appendChild(wrap);
      return { cube, wrap, die };
    });

    // Kurzes Durchblitzen der Werte - eine Animation, kein Dauerfeuer.
    let ticks = 0;
    const timer = setInterval(() => {
      ticks++;
      cubes.forEach(({ cube }) => { cube.textContent = String(1 + Math.floor(Math.random() * 6)); });
      if (ticks >= 6) {
        clearInterval(timer);
        cubes.forEach(({ cube, wrap, die }) => {
          cube.textContent = String(die.value);
          if (die.note) wrap.appendChild(el('span', 'cube__note', die.note));
        });
        showRollResult(roll);
        setTimeout(() => {
          animating = false;
          applyState(next);
          if (queued) {
            const pending = queued;
            queued = null;
            receive(pending);
          }
        }, 620);
      }
    }, 70);
  }

  function showRollResult(roll, silent) {
    const box = $('roll-result');
    box.hidden = false;
    box.classList.toggle('is-perfect', roll.perfect);
    $('result-total').textContent = String(roll.total);

    let label;
    if (roll.perfect) {
      label = roll.streak > 1 ? 'PERFECT · Streak x' + roll.streak : 'PERFECT auf ' + roll.target;
    } else {
      label = 'Ziel ' + roll.target + ' · Abstand ' + roll.distance;
    }
    const labelNode = $('result-label');
    labelNode.textContent = label;
    labelNode.style.color = roll.perfect ? 'var(--gold)' : 'var(--muted)';

    const gains = $('result-gains');
    gains.textContent = '';
    gains.appendChild(el('span', 'gain gain--points', '+' + roll.points + ' Punkte'));
    gains.appendChild(el('span', 'gain gain--money', '+' + roll.money + ' $'));

    const effects = $('result-effects');
    effects.textContent = '';
    roll.effects.slice(0, 6).forEach((eff, i) => {
      const chip = el('span', 'effect');
      chip.appendChild(el('b', null, eff.label));
      chip.appendChild(document.createTextNode(' · ' + eff.detail));
      chip.style.animationDelay = (i * 40) + 'ms';
      effects.appendChild(chip);
    });

    if (roll.perfect && !silent) burst(roll.enchantHit);
  }

  /** Kurzer Effekt: ein Glow plus wenige Funken, danach sofort entfernt. */
  function burst(golden) {
    const flash = el('div', 'flash');
    document.body.appendChild(flash);
    setTimeout(() => flash.remove(), 520);

    const rect = $('roll-result').getBoundingClientRect();
    const cx = rect.left + rect.width / 2;
    const cy = rect.top + rect.height / 2;
    const count = 10;
    for (let i = 0; i < count; i++) {
      const spark = el('div', 'spark');
      const angle = (Math.PI * 2 * i) / count + Math.random() * 0.4;
      const dist = 70 + Math.random() * 60;
      spark.style.left = cx + 'px';
      spark.style.top = cy + 'px';
      spark.style.setProperty('--dx', Math.cos(angle) * dist + 'px');
      spark.style.setProperty('--dy', Math.sin(angle) * dist + 'px');
      spark.style.background = golden ? '#ffc857' : (i % 2 ? '#2ee6c5' : '#6c5cff');
      document.body.appendChild(spark);
      setTimeout(() => spark.remove(), 640);
    }
  }

  /* ----------------------------------------------------------- Shop */
  function isShopOpen() { return !$('shop').hidden; }

  function openShop() {
    $('shop').hidden = false;
    if (state) renderShop(state);
  }
  function closeShop() {
    const shop = $('shop');
    shop.classList.add('is-closing');
    setTimeout(() => {
      shop.hidden = true;
      shop.classList.remove('is-closing');
    }, 190);
  }

  function renderShop(s) {
    const me = s.players[s.you];
    if (!me) return;
    $('shop-money').textContent = String(me.money);
    const signature = me.money + '|' + me.dice.join(',') + '|' + me.potions.join(',') + '|' + shopTab;
    if (sig.shop === signature) return;
    sig.shop = signature;

    buildShopList($('shop-list-dice'), CATALOG.dice, me, 'die');
    buildShopList($('shop-list-potions'), CATALOG.potions, me, 'potion');
  }

  function buildShopList(host, catalog, me, kind) {
    host.textContent = '';
    Object.keys(catalog).forEach((id) => {
      if (kind === 'die' && id === 'basic') return;
      const meta = catalog[id];
      const owned = kind === 'die' ? me.dice.includes(id) : me.potions.includes(id);
      const item = el('div', 'item' + (kind === 'potion' ? ' item--potion' : '') + (owned ? ' is-owned' : ''));
      item.dataset.id = id;

      item.appendChild(el('div', 'item__icon', meta.icon));

      const body = el('div', 'item__body');
      const name = el('div', 'item__name');
      name.appendChild(document.createTextNode(meta.name));
      if (meta.tag) name.appendChild(el('span', 'item__tag', meta.tag));
      body.appendChild(name);
      body.appendChild(el('div', 'item__desc', meta.desc));
      item.appendChild(body);

      if (owned) {
        item.appendChild(el('span', 'item__owned', kind === 'die' ? 'Im Inventar' : 'Aktiv'));
      } else {
        const buy = el('button', 'item__buy', '$' + meta.price);
        buy.disabled = me.money < meta.price;
        buy.addEventListener('click', () => purchase(kind, id, item));
        item.appendChild(buy);
      }
      host.appendChild(item);
    });
  }

  async function purchase(kind, id, node) {
    node.classList.remove('is-bought');
    void node.offsetWidth;
    node.classList.add('is-bought');
    try {
      const data = await api('buy', { code: session.code, token: session.token, kind, id });
      if (data.error) toast(data.error, 'error');
      else toast((kind === 'die' ? 'Würfel' : 'Trank') + ' gekauft', 'good');
      if (data.state) { sig.shop = ''; sig.tray = ''; receive(data.state); }
    } catch (err) {
      toast(err.message, 'error');
    }
  }

  /* --------------------------------------------------------- Result */
  function renderResult(s) {
    const me = s.players[s.you];
    const foe = s.players[1 - s.you];
    const draw = s.winner === -1;
    const won = s.winner === s.you;

    $('result-crown').textContent = draw ? '🤝' : (won ? '🏆' : '💪');
    $('result-title').textContent = draw ? 'Unentschieden' : (won ? 'Du gewinnst!' : foe.name + ' gewinnt');

    const scores = $('result-scores');
    scores.textContent = '';
    scores.appendChild(sideBox(me, !draw && won));
    scores.appendChild(el('div', 'result__vs', 'VS'));
    scores.appendChild(sideBox(foe, !draw && !won));

    const stats = $('result-stats');
    stats.textContent = '';
    [
      me.perfects + ' Punktlandungen',
      'Beste Streak x' + me.bestStreak,
      me.money + ' $ übrig',
      me.dice.length + ' Würfel',
      me.potions.length + ' Tränke',
    ].forEach((t) => stats.appendChild(el('span', null, t)));

    const votes = s.rematchVotes || [];
    $('rematch-hint').textContent = votes.length === 1
      ? (votes[0] === s.you ? 'Warte auf den Gegner...' : foe.name + ' will eine Revanche!')
      : '';
  }

  function sideBox(player, winner) {
    const box = el('div', 'result__side' + (winner ? ' is-winner' : ''));
    box.appendChild(el('div', 'name', player.name));
    box.appendChild(el('div', 'pts', String(player.score)));
    return box;
  }

  async function rematch() {
    try {
      const data = await api('rematch', { code: session.code, token: session.token });
      if (data.error) toast(data.error, 'error');
      if (data.state) { seenRollSeq = data.state.lastRoll ? data.state.lastRoll.seq : 0; receive(data.state); }
    } catch (err) {
      toast(err.message, 'error');
    }
  }

  function goHome() {
    polling = false;
    state = null;
    queued = null;
    seenRollSeq = 0;
    selectedDice = [];
    sig.targets = ''; sig.tray = ''; sig.shop = ''; sig.log = -1;
    clearSession();
    showScreen('screen-home');
  }

  /* ----------------------------------------------------- Copy-Code */
  function copyCode(button) {
    const text = session.code;
    const done = () => {
      button.classList.add('is-copied');
      toast('Raumcode kopiert', 'good');
      setTimeout(() => button.classList.remove('is-copied'), 900);
    };
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(text).then(done).catch(done);
    } else {
      const tmp = el('textarea');
      tmp.value = text;
      document.body.appendChild(tmp);
      tmp.select();
      try { document.execCommand('copy'); } catch { /* ignore */ }
      tmp.remove();
      done();
    }
  }

  /* ------------------------------------------------------- Bindings */
  $('btn-create').addEventListener('click', createRoom);
  $('btn-join').addEventListener('click', joinRoom);
  $('input-code').addEventListener('input', (e) => {
    e.target.value = e.target.value.toUpperCase().replace(/[^A-Z0-9]/g, '');
  });
  $('input-name').addEventListener('keydown', (e) => { if (e.key === 'Enter') createRoom(); });
  $('input-code').addEventListener('keydown', (e) => { if (e.key === 'Enter') joinRoom(); });
  $('btn-leave-lobby').addEventListener('click', goHome);
  $('btn-home').addEventListener('click', goHome);
  $('btn-rematch').addEventListener('click', rematch);
  $('btn-roll').addEventListener('click', doRoll);
  $('btn-shop').addEventListener('click', openShop);
  $('room-code').addEventListener('click', (e) => copyCode(e.currentTarget));
  $('topbar-code').addEventListener('click', (e) => copyCode(e.currentTarget));
  document.querySelectorAll('[data-close-shop]').forEach((n) => n.addEventListener('click', closeShop));
  document.querySelectorAll('.tab').forEach((tab) => {
    tab.addEventListener('click', () => {
      shopTab = tab.dataset.tab;
      document.querySelectorAll('.tab').forEach((t) => t.classList.toggle('is-active', t === tab));
      $('shop-list-dice').hidden = shopTab !== 'dice';
      $('shop-list-potions').hidden = shopTab !== 'potions';
    });
  });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && isShopOpen()) closeShop();
  });

  /* ------------------------------------------------- Reconnect-Flow */
  (async function boot() {
    let saved = null;
    try { saved = JSON.parse(localStorage.getItem(STORAGE_KEY) || 'null'); } catch { saved = null; }
    const url = new URL(window.location.href);
    const codeParam = (url.searchParams.get('room') || '').toUpperCase();
    if (codeParam) $('input-code').value = codeParam;

    if (saved && saved.code && saved.token) {
      try {
        const data = await api('state', { code: saved.code, token: saved.token, v: 0 });
        if (data.state) {
          Object.assign(session, saved);
          seenRollSeq = data.state.lastRoll ? data.state.lastRoll.seq : 0;
          applyState(data.state);
          startPolling();
          return;
        }
      } catch { clearSession(); }
    }
    showScreen('screen-home');
    $('input-name').focus();
  })();
})();
