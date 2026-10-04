/* =========================================================
   Gleiche Hirnzelle – Spieloberfläche
   ========================================================= */
(function () {
  'use strict';

  var API = 'api.php';
  var POLL_MS = 1200;
  var POLL_HIDDEN_MS = 5000;
  var MAX_WORD = 40;

  var app = document.getElementById('app');
  var netbar = document.getElementById('netbar');
  var netbarText = document.getElementById('netbar-text');
  var toastEl = document.getElementById('toast');

  var S = {
    code: null,
    token: null,
    state: null,
    screenKey: null,
    pollTimer: null,
    pollInFlight: false,
    failures: 0,
    stopped: true,
    animated: {},       // bereits animierte Aufdeckungen (game-round)
    finalAnimated: {},  // bereits animierte Endergebnisse (game)
    revealTimers: [],
    loadingTimer: null,
    busy: false
  };

  /* ---------------- Hilfen ---------------- */

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }
  function $(sel, root) { return (root || document).querySelector(sel); }
  function $all(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

  function store(key, val) {
    try {
      if (val === undefined) return JSON.parse(localStorage.getItem(key) || 'null');
      if (val === null) localStorage.removeItem(key); else localStorage.setItem(key, JSON.stringify(val));
    } catch (e) { return null; }
    return null;
  }
  function getTokens() { return store('gh.tokens') || {}; }
  function saveToken(code, token) {
    var t = getTokens(); t[code] = token;
    var keys = Object.keys(t); // nur die letzten 10 Räume merken
    if (keys.length > 10) delete t[keys[0]];
    store('gh.tokens', t); store('gh.last', code);
  }
  function forgetRoom(code) {
    var t = getTokens(); delete t[code]; store('gh.tokens', t);
    if (store('gh.last') === code) store('gh.last', null);
  }

  function baseUrl() {
    var path = location.pathname.replace(/index\.php$/, '');
    return location.origin + path;
  }
  function inviteLink(code) { return baseUrl() + '?r=' + encodeURIComponent(code); }
  function setUrl(code) {
    try { history.replaceState(null, '', code ? '?r=' + encodeURIComponent(code) : location.pathname); } catch (e) { /* egal */ }
  }

  var toastTimer = null;
  function toast(msg, isErr) {
    toastEl.textContent = msg;
    toastEl.className = 'toast' + (isErr ? ' err' : '');
    toastEl.hidden = false;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { toastEl.hidden = true; }, 3600);
  }

  function vibrate(p) { try { if (navigator.vibrate) navigator.vibrate(p); } catch (e) { /* egal */ } }

  function setNet(bad, text) {
    if (bad) {
      netbarText.textContent = text || 'Verbindung unterbrochen – ich versuche es weiter …';
      netbar.hidden = false;
    } else {
      netbar.hidden = true;
    }
  }

  /* ---------------- API ---------------- */

  function api(action, data, timeoutMs) {
    var body = Object.assign({ action: action, code: S.code, token: S.token }, data || {});
    var ctrl = typeof AbortController !== 'undefined' ? new AbortController() : null;
    var timer = setTimeout(function () { if (ctrl) ctrl.abort(); }, timeoutMs || 15000);
    return fetch(API, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
      cache: 'no-store',
      credentials: 'same-origin',
      signal: ctrl ? ctrl.signal : undefined
    }).then(function (res) {
      return res.json().catch(function () { return { ok: false, error: 'Unerwartete Antwort vom Server.' }; })
        .then(function (json) {
          clearTimeout(timer);
          if (!res.ok || !json.ok) {
            var err = new Error(json.error || 'Fehler ' + res.status);
            err.status = res.status; err.code = json.code || 'error';
            throw err;
          }
          return json;
        });
    }, function (e) {
      clearTimeout(timer);
      var err = new Error('Keine Verbindung zum Server.');
      err.network = true; err.cause = e;
      throw err;
    });
  }

  function handleApiError(err, context) {
    if (err.network || err.status >= 500) {
      toast(err.network ? 'Keine Verbindung – bitte nochmals versuchen.' : err.message, true);
      return;
    }
    if (err.code === 'room_not_found') {
      if (S.code) forgetRoom(S.code);
      leaveToHome('Diesen Raum gibt es nicht mehr. Erstellt einfach einen neuen!');
      return;
    }
    if (err.code === 'not_member' && context !== 'join') {
      // Token ungültig geworden – nochmals regulär beitreten.
      joinRoom(S.code, true);
      return;
    }
    if (err.code === 'room_full') {
      leaveToHome(err.message);
      return;
    }
    toast(err.message, true);
  }

  /* ---------------- Polling ---------------- */

  function startPolling() {
    S.stopped = false;
    schedulePoll(10);
  }
  function stopPolling() {
    S.stopped = true;
    clearTimeout(S.pollTimer);
  }
  function schedulePoll(ms) {
    clearTimeout(S.pollTimer);
    if (S.stopped) return;
    S.pollTimer = setTimeout(poll, ms);
  }
  function nextDelay() {
    if (S.failures > 0) return Math.min(POLL_MS * Math.pow(2, S.failures - 1), 8000);
    return document.hidden ? POLL_HIDDEN_MS : POLL_MS;
  }
  function poll() {
    if (S.stopped || !S.code) return;
    if (S.pollInFlight) { schedulePoll(nextDelay()); return; }
    S.pollInFlight = true;
    // Läuft serverseitig gerade ein Claude-Job, kann die Antwort länger dauern.
    api('state', null, 60000).then(function (json) {
      S.pollInFlight = false;
      S.failures = 0;
      if (!netbar.hidden) setNet(false);
      applyState(json.state);
      schedulePoll(nextDelay());
    }, function (err) {
      S.pollInFlight = false;
      if (err.network || err.status >= 500 || err.status === 429) {
        S.failures++;
        if (S.failures >= 2) setNet(true, navigator.onLine === false
          ? 'Du bist offline – sobald das Netz zurück ist, geht es weiter.'
          : 'Verbindung wackelt – ich versuche es weiter …');
        schedulePoll(nextDelay());
      } else {
        stopPolling();
        handleApiError(err, 'state');
      }
    });
  }
  document.addEventListener('visibilitychange', function () {
    if (!document.hidden && S.code && !S.stopped) schedulePoll(10);
  });
  window.addEventListener('online', function () { if (S.code && !S.stopped) { S.failures = 0; schedulePoll(10); } });
  window.addEventListener('offline', function () { if (S.code) setNet(true, 'Du bist offline – sobald das Netz zurück ist, geht es weiter.'); });

  /* ---------------- Ablauf ---------------- */

  function enterRoom(code, token, state) {
    S.code = code; S.token = token;
    saveToken(code, token);
    setUrl(code);
    S.screenKey = null;
    applyState(state);
    startPolling();
  }

  function leaveToHome(msg) {
    stopPolling();
    clearRevealTimers();
    S.code = null; S.token = null; S.state = null; S.screenKey = null;
    setUrl(null);
    setNet(false);
    renderHome();
    if (msg) toast(msg, true);
  }

  function createRoom(btn) {
    if (S.busy) return;
    S.busy = true; setBtnLoading(btn, true);
    S.code = null; S.token = null;
    api('create', null, 20000).then(function (json) {
      S.busy = false;
      enterRoom(json.state.code, json.token, json.state);
    }, function (err) {
      S.busy = false; setBtnLoading(btn, false);
      toast(err.network ? 'Keine Verbindung zum Server.' : err.message, true);
    });
  }

  function joinRoom(code, silent, btn) {
    code = String(code || '').toUpperCase().replace(/[^A-Z0-9]/g, '');
    if (code.length !== 5) {
      var inp = $('#code-input');
      if (inp) shake(inp);
      toast('Der Raumcode hat 5 Zeichen.', true);
      return;
    }
    if (S.busy) return;
    S.busy = true; if (btn) setBtnLoading(btn, true);
    S.code = code;
    S.token = getTokens()[code] || '';
    if (!silent && !btn) renderLoading('Raum wird betreten …');
    api('join', null, 20000).then(function (json) {
      S.busy = false;
      if (json.takeover) toast('Willkommen zurück! Du hast deinen Platz wieder übernommen.');
      enterRoom(code, json.token, json.state);
    }, function (err) {
      S.busy = false; if (btn) setBtnLoading(btn, false);
      if (err.network) {
        // Beim Netzabbruch einfach weiter versuchen, wenn wir das Token schon haben.
        if (S.token) { enterRoom(code, S.token, S.state); return; }
        leaveToHome('Keine Verbindung zum Server. Bitte nochmals versuchen.');
        return;
      }
      if (err.code === 'room_not_found') forgetRoom(code);
      leaveToHome(err.message);
    });
  }

  function act(action, data, btn) {
    if (S.busy) return Promise.resolve();
    S.busy = true;
    if (btn) setBtnLoading(btn, true);
    return api(action, data, 20000).then(function (json) {
      S.busy = false;
      applyState(json.state);
      schedulePoll(60); // offene Claude-Aufgabe sofort anstossen
    }, function (err) {
      S.busy = false;
      if (btn) setBtnLoading(btn, false);
      handleApiError(err, action);
      throw err;
    });
  }

  function setBtnLoading(btn, on) {
    if (!btn) return;
    if (on) {
      btn.dataset.label = btn.innerHTML;
      btn.innerHTML = '<span class="spin"></span>';
      btn.disabled = true;
    } else if (btn.dataset.label) {
      btn.innerHTML = btn.dataset.label;
      btn.disabled = false;
    }
  }

  function shake(el) {
    el.classList.remove('shake');
    void el.offsetWidth;
    el.classList.add('shake');
    vibrate(60);
  }

  /* ---------------- Zustand → Bildschirm ---------------- */

  function applyState(state) {
    if (!state) return;
    var prev = S.state;
    if (prev && prev.version > state.version && prev.game === state.game) return; // veraltete Antwort
    S.state = state;
    var key = screenKeyFor(state);
    if (key !== S.screenKey) {
      S.screenKey = key;
      clearRevealTimers();
      try { window.scrollTo(0, 0); } catch (e) { /* egal */ }
      renderScreen(state);
    } else {
      updateScreen(state);
    }
  }

  function screenKeyFor(st) {
    var k = st.phase + ':' + st.game + ':' + st.round;
    if (st.phase === 'question') k += ':' + (st.myAnswer ? 'sent' : 'open');
    if (st.phase === 'lobby') k += ':' + st.players.length;
    return k;
  }

  function renderScreen(st) {
    switch (st.phase) {
      case 'lobby': return renderLobby(st);
      case 'question_loading': return renderLoading(st.round === 1 ? 'Claude brütet eure erste Frage aus …' : 'Claude denkt sich Frage ' + st.round + ' aus …', st);
      case 'question': return renderQuestion(st);
      case 'scoring': return renderScoring(st);
      case 'reveal': return renderReveal(st);
      case 'title_loading': return renderLoading('Claude tauft euer Duo …', st);
      case 'finished': return renderFinished(st);
    }
  }

  function updateScreen(st) {
    var pl = $('#players');
    if (pl) pl.innerHTML = playersHtml(st);
    var w = $('#wait-info');
    if (w) w.innerHTML = waitInfoHtml(st);
    var off = $('#offline-note');
    if (off) off.innerHTML = offlineNoteHtml(st);
    if (st.phase === 'reveal') updateRevealButton(st);
  }

  function me(st) { return st.players[st.me]; }
  function partner(st) { return st.players[1 - st.me] || null; }

  /* ---------------- Bausteine ---------------- */

  function topbar(st) {
    var dots = '';
    for (var i = 1; i <= st.totalRounds; i++) {
      var r = st.results[i - 1];
      var cls = r ? (r.score === 10 ? 'perfect' : 'done') : (i === st.round && st.phase !== 'finished' && st.phase !== 'title_loading' ? 'now' : '');
      dots += '<i class="' + cls + '"></i>';
    }
    return '<div class="topbar">' +
      '<button class="pill" id="home-btn" aria-label="Zur Startseite">🏠 ' + esc(st.code) + '</button>' +
      '<span class="pill"><span>Runde <b>' + Math.min(st.round, st.totalRounds) + '</b>/' + st.totalRounds + '</span></span>' +
      '<span class="pill score">🧠 <b id="total-pill">' + st.total + '</b></span>' +
      '</div>' +
      '<div class="progress" aria-hidden="true">' + dots + '</div>';
  }

  function playerCard(p, st, kind) {
    var status = '';
    if (!p.online) status = '<span class="status off">offline</span>';
    else if (kind === 'answer') status = p.answered ? '<span class="status ok">✓ fertig</span>' : '<span class="status wait">denkt …</span>';
    else if (kind === 'ready') status = p.ready ? '<span class="status ok">✓ bereit</span>' : '';
    return '<div class="player' + (p.isMe ? ' me' : '') + '" style="--c:' + esc(p.color) + '">' +
      status +
      '<div class="avatar">' + esc(p.emoji) + '</div>' +
      '<div class="pname">' + esc(p.name) + '</div>' +
      '<div class="ptag">' + (p.isMe ? 'Du' : '<span class="online-dot' + (p.online ? '' : ' off') + '"></span>Partner-Hirn') + '</div>' +
      '</div>';
  }

  function playersHtml(st) {
    var kind = st.phase === 'question' ? 'answer' : (st.phase === 'reveal' ? 'ready' : '');
    var out = '';
    var order = [st.me, 1 - st.me];
    order.forEach(function (i) {
      var p = st.players[i];
      if (p) out += playerCard(p, st, kind);
      else out += '<div class="player empty"><div class="avatar">❔</div><div class="pname">Wartet …</div><div class="ptag" style="color:#fff">Noch leer</div></div>';
    });
    return out;
  }

  function offlineNoteHtml(st) {
    var p = partner(st);
    if (!p || p.online || st.phase === 'lobby') return '';
    return '<div class="notice">📡 ' + esc(p.name) + ' ist gerade offline. Keine Sorge – das Spiel wartet, und mit dem Einladungslink geht es jederzeit weiter.</div>';
  }

  function waitInfoHtml(st) {
    var p = partner(st);
    if (!p) return '';
    if (st.phase === 'question' && st.myAnswer) {
      return p.answered
        ? '<div class="waiting">Beide fertig! Gleich wird aufgedeckt …</div>'
        : '<div class="waiting">Warte auf ' + esc(p.name) + ' <span class="dots"><span>.</span><span>.</span><span>.</span></span></div>';
    }
    if (st.phase === 'reveal') {
      var mine = me(st);
      if (mine.ready && !p.ready) {
        var extra = !p.online ? '<div class="tiny" style="opacity:.85;margin-top:4px">' + esc(p.name) + ' ist offline – es geht gleich automatisch weiter.</div>' : '';
        return '<div class="waiting" style="flex-direction:column">Warte, bis ' + esc(p.name) + ' bereit ist <span class="dots"><span>.</span><span>.</span><span>.</span></span>' + extra + '</div>';
      }
      if (!mine.ready && p.ready) return '<div class="waiting">' + esc(p.name) + ' ist schon bereit! 👀</div>';
    }
    return '';
  }

  /* ---------------- Bildschirme ---------------- */

  function renderHome() {
    clearRevealTimers();
    var last = store('gh.last');
    var tokens = getTokens();
    var resume = last && tokens[last]
      ? '<button class="btn ghost" id="resume-btn">↩︎ Zurück in Raum ' + esc(last) + '</button>'
      : '';
    app.innerHTML =
      '<div class="screen">' +
        '<div class="logo">' +
          '<div class="logo-brain">🧠</div>' +
          '<h1>Gleiche <span>Hirnzelle</span></h1>' +
          '<p>Zwei Köpfe. Eine Frage. Ein Wort.<br>Teilt ihr euch eine Hirnzelle?</p>' +
        '</div>' +
        '<button class="btn" id="create-btn">✨ Raum erstellen</button>' +
        '<div class="divider">oder beitreten</div>' +
        '<form class="card stack" id="join-form" autocomplete="off">' +
          '<input class="input code" id="code-input" inputmode="text" maxlength="5" placeholder="CODE" aria-label="Raumcode" autocapitalize="characters" spellcheck="false" autocomplete="off">' +
          '<button class="btn purple" type="submit" id="join-btn">🚪 Beitreten</button>' +
        '</form>' +
        resume +
        '<details class="card soft" id="rules">' +
          '<summary style="cursor:pointer;font-weight:600;font-size:1.1rem">🤔 Wie funktioniert\'s?</summary>' +
          '<ul class="rules" style="margin-top:14px">' +
            '<li><span class="ri">👯</span><span>Genau zwei Personen, jede am eigenen Handy. Eine erstellt den Raum, die andere tritt per Code, Link oder QR-Code bei.</span></li>' +
            '<li><span class="ri">❓</span><span>Claude stellt pro Runde eine verrückte Frage.</span></li>' +
            '<li><span class="ri">🤫</span><span>Beide antworten geheim mit genau <b>einem Wort</b> – egal welchem.</span></li>' +
            '<li><span class="ri">🎭</span><span>Dann wird gleichzeitig aufgedeckt. Gleiches Wort = <b>10 Punkte</b>. Sonst bewertet Claude, wie nah ihr wart (1–9).</span></li>' +
            '<li><span class="ri">🏆</span><span>Nach 10 Runden: maximal 100 Punkte und euer offizieller Duo-Titel.</span></li>' +
          '</ul>' +
        '</details>' +
      '</div>';

    $('#create-btn').addEventListener('click', function () { createRoom(this); });
    var input = $('#code-input');
    input.addEventListener('input', function () {
      var v = input.value.toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 5);
      if (v !== input.value) input.value = v;
    });
    $('#join-form').addEventListener('submit', function (e) {
      e.preventDefault();
      joinRoom(input.value, false, $('#join-btn'));
    });
    var rb = $('#resume-btn');
    if (rb) rb.addEventListener('click', function () { joinRoom(last, false, rb); });
  }

  var LOADING_LINES = [
    'Hirnzellen werden poliert …',
    'Claude kratzt sich am Kopf …',
    'Neuronen machen Dehnübungen …',
    'Synapsen werden verknotet …',
    'Fragen-Ei wird ausgebrütet …',
    'Gedankenwellen werden kalibriert …'
  ];

  function renderLoading(text, st) {
    clearInterval(S.loadingTimer);
    app.innerHTML =
      '<div class="screen">' +
        (st ? topbar(st) : '') +
        '<div class="spacer"></div>' +
        '<div class="center-text">' +
          '<div class="brain-loader"><span>🧠</span></div>' +
          '<div class="loading-text">' + esc(text) + '</div>' +
          '<div class="white small" id="loading-sub" style="opacity:.85">' + esc(LOADING_LINES[0]) + '</div>' +
        '</div>' +
        '<div class="spacer"></div>' +
        (st ? '<div class="players" id="players">' + playersHtml(st) + '</div><div id="offline-note">' + offlineNoteHtml(st) + '</div>' : '') +
      '</div>';
    bindTop();
    var i = 0;
    S.loadingTimer = setInterval(function () {
      var el = $('#loading-sub');
      if (!el) { clearInterval(S.loadingTimer); return; }
      i = (i + 1) % LOADING_LINES.length;
      el.textContent = LOADING_LINES[i];
    }, 2200);
  }

  function renderLobby(st) {
    var link = inviteLink(st.code);
    var canShare = !!navigator.share;
    app.innerHTML =
      '<div class="screen">' +
        '<div class="topbar"><button class="pill" id="home-btn">🏠 Start</button><span class="pill">Lobby</span></div>' +
        '<div class="card stack center-text">' +
          '<div class="muted small" style="font-weight:600">Euer Raumcode</div>' +
          '<div class="code-big" id="code-big">' + esc(st.code) + '</div>' +
          '<div class="qr" id="qr" role="img" aria-label="QR-Code mit Einladungslink"></div>' +
          '<div class="muted tiny">Scannen, Link schicken oder Code eintippen</div>' +
          '<div class="share-row">' +
            (canShare ? '<button class="btn pink small" id="share-btn" style="width:100%">📤 Teilen</button>' : '') +
            '<button class="btn purple small" id="copy-btn" style="width:100%' + (canShare ? '' : ';grid-column:span 2') + '">🔗 Link kopieren</button>' +
          '</div>' +
        '</div>' +
        '<div class="players" id="players">' + playersHtml(st) + '</div>' +
        '<button class="btn ghost" id="rename-btn">🎲 Anderer Name für mich</button>' +
        '<div class="waiting">Warte auf deine zweite Hirnzellen-Hälfte <span class="dots"><span>.</span><span>.</span><span>.</span></span></div>' +
      '</div>';
    bindTop();
    drawQr($('#qr'), link);
    $('#copy-btn').addEventListener('click', function () { copyText(link); });
    var sb = $('#share-btn');
    if (sb) sb.addEventListener('click', function () {
      navigator.share({ title: 'Gleiche Hirnzelle', text: 'Teilen wir uns eine Hirnzelle? Komm in meinen Raum ' + st.code + ' 🧠', url: link }).catch(function () { /* abgebrochen */ });
    });
    $('#rename-btn').addEventListener('click', function () { act('rename', null, this).catch(function () {}); S.screenKey = null; });
  }

  function drawQr(el, text) {
    if (!el) return;
    try {
      var qr = qrcode(0, 'M');
      qr.addData(text);
      qr.make();
      el.innerHTML = qr.createSvgTag({ cellSize: 6, margin: 2, scalable: true });
    } catch (e) {
      el.innerHTML = '<div class="muted small" style="padding-top:60px">QR-Code nicht verfügbar</div>';
    }
  }

  function copyText(text) {
    var done = function () { toast('Link kopiert! Jetzt einfach verschicken. 📨'); };
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(text).then(done, fallback);
    } else fallback();
    function fallback() {
      var ta = document.createElement('textarea');
      ta.value = text; ta.setAttribute('readonly', ''); ta.style.position = 'fixed'; ta.style.opacity = '0';
      document.body.appendChild(ta); ta.select();
      try { document.execCommand('copy'); done(); } catch (e) { toast('Kopieren klappt nicht – Link: ' + text); }
      document.body.removeChild(ta);
    }
  }

  function renderQuestion(st) {
    var sent = !!st.myAnswer;
    var body;
    if (!sent) {
      body =
        '<form class="card answer-form" id="answer-form" autocomplete="off">' +
          '<input class="input" id="answer-input" maxlength="' + MAX_WORD + '" placeholder="Dein Wort" aria-label="Deine Antwort (ein Wort)" ' +
            'autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false" enterkeyhint="send">' +
          '<div class="hint" id="answer-hint">Genau ein Wort. Alles ist erlaubt – auch Erfundenes. 🤫</div>' +
          '<button class="btn pink" type="submit" id="answer-btn">🔒 Geheim abschicken</button>' +
        '</form>';
    } else {
      body =
        '<div class="card sealed">' +
          '<div class="envelope">💌</div>' +
          '<div style="font-weight:700;font-size:1.25rem">Versiegelt!</div>' +
          '<div class="mine">Dein Wort: <b>' + esc(st.myAnswer) + '</b></div>' +
        '</div>';
    }
    app.innerHTML =
      '<div class="screen">' +
        topbar(st) +
        '<div class="card question-card' + (sent ? '' : ' pop') + '">' +
          '<div class="qlabel">Frage ' + st.round + '</div>' +
          '<p class="qtext">' + esc(st.question) + '</p>' +
        '</div>' +
        body +
        '<div id="wait-info">' + waitInfoHtml(st) + '</div>' +
        '<div class="spacer"></div>' +
        '<div class="players" id="players">' + playersHtml(st) + '</div>' +
        '<div id="offline-note">' + offlineNoteHtml(st) + '</div>' +
      '</div>';
    bindTop();
    if (sent) return;

    var input = $('#answer-input');
    var hint = $('#answer-hint');
    var defaultHint = hint.textContent;
    input.addEventListener('input', function () {
      if (/\S\s+\S/.test(input.value)) {
        hint.textContent = 'Nur EIN Wort – ohne Leerzeichen! ☝️';
        hint.style.color = 'var(--pink)';
      } else {
        hint.textContent = defaultHint;
        hint.style.color = '';
      }
    });
    input.addEventListener('keydown', function (e) {
      if (e.key === ' ' && input.value.trim().length > 0) {
        e.preventDefault();
        shake(input);
        hint.textContent = 'Nur EIN Wort – ohne Leerzeichen! ☝️';
        hint.style.color = 'var(--pink)';
      }
    });
    $('#answer-form').addEventListener('submit', function (e) {
      e.preventDefault();
      var word = input.value.trim();
      if (!word) { shake(input); hint.textContent = 'Schreib ein Wort, irgendeins! ✍️'; hint.style.color = 'var(--pink)'; return; }
      if (/\s/.test(word)) { shake(input); hint.textContent = 'Nur EIN Wort – ohne Leerzeichen! ☝️'; hint.style.color = 'var(--pink)'; return; }
      input.blur();
      vibrate(30);
      act('answer', { answer: word }, $('#answer-btn')).catch(function () {});
    });
    // Fokus nur auf Geräten mit Maus, damit die Handy-Tastatur nicht die Frage verdeckt.
    if (window.matchMedia && window.matchMedia('(hover: hover)').matches) input.focus();
  }

  function cardsHtml(st, words) {
    var order = [st.me, 1 - st.me];
    return order.map(function (i) {
      var p = st.players[i];
      return '<div class="flip" style="--c:' + esc(p.color) + '">' +
        '<div class="face back">🧠</div>' +
        '<div class="face front">' +
          '<div class="who">' + esc(p.emoji) + ' <span>' + esc(p.isMe ? 'Du' : p.name) + '</span></div>' +
          '<div class="word">' + esc(words ? words[i] : '') + '</div>' +
        '</div>' +
      '</div>';
    }).join('');
  }

  function renderScoring(st) {
    app.innerHTML =
      '<div class="screen">' +
        topbar(st) +
        '<div class="card question-card compact"><div class="qlabel">Frage ' + st.round + '</div><p class="qtext">' + esc(st.question) + '</p></div>' +
        '<div class="banner" style="font-size:1.6rem;color:#fff">Beide haben abgegeben! 🥁</div>' +
        '<div class="stage shake">' + cardsHtml(st, null) + '</div>' +
        '<div class="center-text white" style="font-weight:600;font-size:1.1rem">Claude vergleicht eure Hirnzellen <span class="dots"><span>.</span><span>.</span><span>.</span></span></div>' +
        '<div class="spacer"></div>' +
        '<div id="offline-note">' + offlineNoteHtml(st) + '</div>' +
      '</div>';
    bindTop();
    vibrate([40, 60, 40]);
  }

  function verdictFor(score) {
    if (score === 10) return 'GLEICHE HIRNZELLE! 🧠✨';
    if (score >= 8) return 'Sooo knapp! 🔥';
    if (score >= 6) return 'Gleiche Wellenlänge 📡';
    if (score >= 4) return 'Irgendwie verwandt 🤝';
    if (score >= 2) return 'Getrennte Galaxien 🪐';
    return 'Komplett andere Planeten 👽';
  }

  function renderReveal(st) {
    var r = st.results[st.results.length - 1];
    if (!r) return;
    var key = st.game + '-' + r.round;
    var animate = !S.animated[key] && st.phaseAge < 25;
    S.animated[key] = true;
    var isLast = st.round >= st.totalRounds;

    app.innerHTML =
      '<div class="screen">' +
        topbar(st) +
        '<div class="card question-card compact"><div class="qlabel">Frage ' + st.round + '</div><p class="qtext">' + esc(r.question) + '</p></div>' +
        '<div class="countdown" id="countdown"></div>' +
        '<div class="stage" id="stage">' + cardsHtml(st, r.answers) + '</div>' +
        '<div id="match-banner"></div>' +
        '<div class="card result result-reveal" id="result">' +
          '<div class="score-num"><span id="score-num">0</span><small>/10</small></div>' +
          '<div class="meter"><i id="meter"></i></div>' +
          '<div class="meter-scale"><span>komplett anders</span><span>identisch</span></div>' +
          '<div class="verdict">' + esc(verdictFor(r.score)) + '</div>' +
          '<div class="bubble"><span class="bot">🤖</span><span>' + esc(r.comment) + '</span></div>' +
          (r.source === 'fallback' ? '<div class="src-note">Claude war kurz nicht erreichbar – bewertet nach Buchstabenähnlichkeit.</div>' : '') +
        '</div>' +
        '<div class="result-reveal stack" id="after">' +
          '<button class="btn green" id="next-btn">' + (isLast ? '🏆 Zum Endergebnis' : '➜ Nächste Frage') + '</button>' +
          '<div id="wait-info">' + waitInfoHtml(st) + '</div>' +
        '</div>' +
        '<div class="players" id="players">' + playersHtml(st) + '</div>' +
        '<div id="offline-note">' + offlineNoteHtml(st) + '</div>' +
      '</div>';
    bindTop();
    $('#next-btn').addEventListener('click', function () {
      act('next', null, this).catch(function () {});
    });
    updateRevealButton(st);

    var total = st.total;
    var totalPill = $('#total-pill');

    var showResult = function () {
      var resEl = $('#result');
      resEl.classList.add('on');
      if (animate) {
        later(function () {
          var rect = resEl.getBoundingClientRect();
          if (rect.bottom > window.innerHeight) {
            try { resEl.scrollIntoView({ behavior: 'smooth', block: 'center' }); } catch (e) { resEl.scrollIntoView(); }
          }
        }, 350);
      }
      countUp($('#score-num'), r.score, animate ? 900 : 0);
      setTimeout(function () { $('#meter') && ($('#meter').style.width = (r.score * 10) + '%'); }, animate ? 60 : 0);
      if (totalPill) countUp(totalPill, total, animate ? 900 : 0, total - r.score);
      if (r.score === 10) {
        $('#stage').classList.add('match');
        $('#match-banner').innerHTML = '<div class="banner">GLEICHE HIRNZELLE!</div>';
        if (animate) { confetti(220); vibrate([80, 50, 80, 50, 160]); }
      } else if (r.score >= 8 && animate) {
        confetti(60);
      }
      later(function () { var a = $('#after'); if (a) a.classList.add('on'); }, animate ? 900 : 0);
    };

    var cards = $all('.flip', $('#stage'));
    if (!animate) {
      cards.forEach(function (c) { c.classList.add('flipped'); });
      $('#countdown').style.display = 'none';
      showResult();
      return;
    }
    // Dramatische Aufdeckung: Trommelwirbel, Countdown, gleichzeitiges Umdrehen.
    $('#stage').classList.add('shake');
    var cd = $('#countdown');
    var steps = ['3', '2', '1'];
    steps.forEach(function (n, idx) {
      later(function () {
        cd.textContent = n;
        cd.classList.remove('tick'); void cd.offsetWidth; cd.classList.add('tick');
        vibrate(25);
      }, idx * 750);
    });
    later(function () {
      cd.textContent = '';
      $('#stage').classList.remove('shake');
      cards.forEach(function (c) { c.classList.add('flipped'); });
      vibrate(70);
    }, steps.length * 750);
    later(function () {
      cd.style.display = 'none';
      showResult();
    }, steps.length * 750 + 950);
  }

  function updateRevealButton(st) {
    var btn = $('#next-btn');
    if (!btn) return;
    var mine = me(st);
    if (mine.ready) {
      btn.disabled = true;
      btn.innerHTML = '✓ Bereit';
      btn.dataset.label = '';
    }
  }

  function renderFinished(st) {
    var key = st.game;
    var animate = !S.finalAnimated[key] && st.phaseAge < 30;
    S.finalAnimated[key] = true;
    var t = st.title || { title: 'Das Rätselhafte Duo', subtitle: '' };
    var recap = st.results.map(function (r) {
      var a = r.answers[st.me], b = r.answers[1 - st.me];
      return '<li>' +
        '<span class="rn">' + r.round + '</span>' +
        '<span><div class="rq">' + esc(r.question) + '</div><div class="ra">' + esc(a) + ' · ' + esc(b) + '</div></span>' +
        '<span class="rs' + (r.score === 10 ? ' perfect' : '') + '">' + r.score + '</span>' +
      '</li>';
    }).join('');
    var perfect = st.results.filter(function (r) { return r.score === 10; }).length;
    var p = partner(st);

    app.innerHTML =
      '<div class="screen">' +
        '<div class="topbar"><button class="pill" id="home-btn">🏠 ' + esc(st.code) + '</button><span class="pill">Spiel ' + st.game + ' vorbei</span></div>' +
        '<div class="trophy">🏆</div>' +
        '<div class="final-total"><span id="final-num">' + (animate ? 0 : st.total) + '</span><small>/100</small></div>' +
        '<div class="center-text white" style="font-weight:600">' + perfect + '× gleiche Hirnzelle · ' + esc(me(st).name) + ' & ' + esc(p ? p.name : '?') + '</div>' +
        '<div class="card title-card result-reveal" id="title-card">' +
          '<div class="label">Euer offizieller Duo-Titel</div>' +
          '<h2>' + esc(t.title) + '</h2>' +
          (t.subtitle ? '<p>' + esc(t.subtitle) + '</p>' : '') +
        '</div>' +
        '<button class="btn" id="again-btn">🔁 Nochmal spielen</button>' +
        '<button class="btn ghost" id="new-btn">✨ Neuer Raum</button>' +
        '<div class="card"><div style="font-weight:700;margin-bottom:10px">Rückblick</div><ul class="recap">' + recap + '</ul></div>' +
        '<div class="players" id="players">' + playersHtml(st) + '</div>' +
        '<div id="offline-note">' + offlineNoteHtml(st) + '</div>' +
      '</div>';
    bindTop();
    $('#again-btn').addEventListener('click', function () { act('restart', null, this).catch(function () {}); });
    $('#new-btn').addEventListener('click', function () {
      stopPolling();
      S.code = null; S.token = null; S.state = null; S.screenKey = null;
      setUrl(null);
      createRoom(this);
    });
    if (animate) {
      countUp($('#final-num'), st.total, 1600);
      later(function () {
        var tc = $('#title-card'); if (tc) tc.classList.add('on');
        confetti(st.total >= 60 ? 260 : 120);
        vibrate([60, 40, 120]);
      }, 1700);
    } else {
      $('#title-card').classList.add('on');
    }
  }

  function bindTop() {
    var hb = $('#home-btn');
    if (hb) hb.addEventListener('click', function () {
      if (confirm('Zur Startseite? Du kannst später mit dem Code oder Link wieder zurück.')) leaveToHome();
    });
  }

  /* ---------------- Animationen ---------------- */

  function later(fn, ms) { S.revealTimers.push(setTimeout(fn, ms)); }
  function clearRevealTimers() {
    S.revealTimers.forEach(clearTimeout); S.revealTimers = [];
    clearInterval(S.loadingTimer);
  }

  function countUp(el, to, ms, from) {
    if (!el) return;
    from = from || 0;
    if (!ms) { el.textContent = to; return; }
    var start = null;
    function step(ts) {
      if (start === null) start = ts;
      var k = Math.min(1, (ts - start) / ms);
      var e = 1 - Math.pow(1 - k, 3);
      el.textContent = Math.round(from + (to - from) * e);
      if (k < 1) requestAnimationFrame(step);
    }
    requestAnimationFrame(step);
  }

  var confettiCanvas = document.getElementById('confetti');
  var confettiParts = [];
  var confettiRunning = false;
  function confetti(n) {
    if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    var c = confettiCanvas;
    var dpr = Math.min(window.devicePixelRatio || 1, 2);
    c.width = innerWidth * dpr; c.height = innerHeight * dpr;
    var colors = ['#ff4d8d', '#ffcc33', '#14c38e', '#3d7bff', '#7b3cff', '#ff7a2f', '#ffffff'];
    for (var i = 0; i < n; i++) {
      confettiParts.push({
        x: innerWidth / 2 + (Math.random() - 0.5) * innerWidth * 0.4,
        y: innerHeight * 0.35,
        vx: (Math.random() - 0.5) * 14,
        vy: -Math.random() * 15 - 5,
        r: Math.random() * 6 + 4,
        rot: Math.random() * Math.PI,
        vr: (Math.random() - 0.5) * 0.4,
        color: colors[(Math.random() * colors.length) | 0],
        shape: Math.random() < 0.3 ? 'circle' : 'rect',
        life: 0
      });
    }
    if (!confettiRunning) { confettiRunning = true; requestAnimationFrame(confettiStep); }
  }
  function confettiStep() {
    var c = confettiCanvas, ctx = c.getContext('2d');
    var dpr = c.width / innerWidth || 1;
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    ctx.clearRect(0, 0, innerWidth, innerHeight);
    confettiParts = confettiParts.filter(function (p) { return p.y < innerHeight + 40 && p.life < 400; });
    confettiParts.forEach(function (p) {
      p.life++;
      p.vy += 0.38; p.vx *= 0.99; p.vy *= 0.99;
      p.x += p.vx; p.y += p.vy; p.rot += p.vr;
      ctx.save();
      ctx.translate(p.x, p.y); ctx.rotate(p.rot);
      ctx.fillStyle = p.color;
      if (p.shape === 'circle') { ctx.beginPath(); ctx.arc(0, 0, p.r / 2, 0, Math.PI * 2); ctx.fill(); }
      else ctx.fillRect(-p.r / 2, -p.r / 4, p.r, p.r / 2);
      ctx.restore();
    });
    if (confettiParts.length) requestAnimationFrame(confettiStep);
    else { confettiRunning = false; ctx.clearRect(0, 0, innerWidth, innerHeight); }
  }

  /* ---------------- Start ---------------- */

  function boot() {
    var params = new URLSearchParams(location.search);
    var code = (params.get('r') || params.get('raum') || '').toUpperCase().replace(/[^A-Z0-9]/g, '');
    if (code.length === 5) {
      joinRoom(code, false);
    } else {
      renderHome();
    }
  }

  boot();
})();
