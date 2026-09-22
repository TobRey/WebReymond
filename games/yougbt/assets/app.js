/* YouGBT – Client. Zeigt nur an, was der Server erlaubt; alle Regeln liegen serverseitig. */
(function () {
  'use strict';

  // ------------------------------------------------------------------ Hilfen
  var I18N = window.YG_I18N;
  var app = document.getElementById('app');
  var store = {
    get: function (k, d) { try { var v = localStorage.getItem('yg_' + k); return v === null ? d : JSON.parse(v); } catch (e) { return d; } },
    set: function (k, v) { try { localStorage.setItem('yg_' + k, JSON.stringify(v)); } catch (e) { /* privat/blockiert */ } }
  };
  var mqReduce = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)') : { matches: false };
  var S = {
    lang: store.get('lang', 'de') === 'en' ? 'en' : 'de',
    theme: store.get('theme', null),
    motion: store.get('motion', null), // null = System, 'reduce', 'full'
    sess: null, state: null, v: 0, offset: 0,
    pollT: null, tickT: null, inflight: false, advancing: false, failCount: 0,
    draft: store.get('draft', {}), joker: {}, typed: {}, celebrated: {}, screenKey: '', chatSeen: 0, chatOpen: false,
    lastRender: 0
  };

  function t(key, vars) {
    var s = (I18N[S.lang] && I18N[S.lang][key]) || I18N.de[key] || key;
    if (vars) { Object.keys(vars).forEach(function (k) { s = s.split('{' + k + '}').join(String(vars[k])); }); }
    return s;
  }
  function reduced() { return S.motion === 'reduce' || (S.motion !== 'full' && mqReduce.matches); }

  /** DOM-Baukasten: Strings werden immer als Text eingefügt (kein innerHTML mit Daten → kein XSS). */
  function h(tag, attrs) {
    var el = document.createElement(tag);
    if (attrs) {
      Object.keys(attrs).forEach(function (k) {
        var v = attrs[k];
        if (v === null || v === undefined || v === false) return;
        if (k === 'class') el.className = v;
        else if (k === 'text') el.textContent = v;
        else if (k === 'style') el.setAttribute('style', v);
        else if (k.slice(0, 2) === 'on') el.addEventListener(k.slice(2), v);
        else if (v === true) el.setAttribute(k, '');
        else el.setAttribute(k, v);
      });
    }
    for (var i = 2; i < arguments.length; i++) add(el, arguments[i]);
    return el;
  }
  function add(el, c) {
    if (c === null || c === undefined || c === false) return;
    if (Array.isArray(c)) { c.forEach(function (x) { add(el, x); }); return; }
    el.appendChild(typeof c === 'object' ? c : document.createTextNode(String(c)));
  }
  function clear(el) { while (el.firstChild) el.removeChild(el.firstChild); }
  function $(sel, root) { return (root || document).querySelector(sel); }

  function toast(msg, kind) {
    var el = document.getElementById('toast');
    el.textContent = msg;
    el.className = 'toast show ' + (kind || '');
    clearTimeout(toast._t);
    toast._t = setTimeout(function () { el.className = 'toast'; }, 3800);
  }

  function hueOf(str) { var x = 0; for (var i = 0; i < str.length; i++) x = (x * 31 + str.charCodeAt(i)) % 360; return x; }
  function avatar(name, cls) {
    var parts = String(name).replace(/ AI$/, '').trim().split(/\s+/);
    var ini = (parts[0] || '?').charAt(0) + (parts[1] ? parts[1].charAt(0) : '');
    var hu = hueOf(name);
    return h('span', { class: 'avatar ' + (cls || ''), style: '--h:' + hu, 'aria-hidden': 'true' }, ini.toUpperCase());
  }
  function aiText(msg) {
    var me = S.state && S.state.me ? S.state.me.name : 'AI';
    return String(msg).split('{AI}').join(me);
  }
  function serverNow() { return Date.now() + S.offset; }

  // ------------------------------------------------------------------ API
  function api(a, data) {
    var body = Object.assign({ a: a }, data || {});
    if (S.sess && body.code === undefined && a !== 'create' && a !== 'join' && a !== 'status' && a !== 'peek') {
      body.code = S.sess.code; body.pid = S.sess.pid; body.token = S.sess.token;
    }
    var sent = Date.now();
    return fetch('api.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-YouGBT': '1' },
      body: JSON.stringify(body),
      cache: 'no-store',
      credentials: 'same-origin'
    }).then(function (r) {
      return r.json().catch(function () { return { ok: false, error: 'server_error' }; });
    }, function () {
      return { ok: false, error: 'network' };
    }).then(function (res) {
      if (res && res.state) acceptState(res.state, Date.now() - sent);
      else if (res && res.now) S.offset = res.now - Date.now() + (Date.now() - sent) / 2;
      return res;
    });
  }
  function errMsg(code) { return t('err_' + code) !== 'err_' + code ? t('err_' + code) : t('err_generic'); }

  // ------------------------------------------------------------------ Sitzungen (Wiederverbindung)
  function sessions() { return store.get('sessions', {}); }
  function saveSession(code, pid, token) {
    var all = sessions();
    all[code] = { code: code, pid: pid, token: token, t: Date.now() };
    Object.keys(all).forEach(function (k) { if (Date.now() - all[k].t > 864e5) delete all[k]; });
    store.set('sessions', all);
  }
  function dropSession(code) { var all = sessions(); delete all[code]; store.set('sessions', all); }
  function setUrl(code) {
    var url = location.pathname + (code ? '?r=' + encodeURIComponent(code) : '');
    history.replaceState(null, '', url);
  }
  function inviteLink(code) {
    return location.origin + location.pathname.replace(/[^/]*$/, '') + '?r=' + code;
  }

  // ------------------------------------------------------------------ Einstellungen (Theme, Sprache, Bewegung)
  function applyPrefs() {
    var theme = S.theme || (window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark');
    document.documentElement.setAttribute('data-theme', theme);
    document.documentElement.setAttribute('lang', S.lang);
    document.documentElement.classList.toggle('reduce-motion', reduced());
    $('#btn-theme').textContent = theme === 'dark' ? '☾' : '☀';
    $('#btn-theme').setAttribute('aria-label', t('theme_toggle'));
    $('#btn-theme').title = t('theme_toggle');
    $('#btn-lang').textContent = S.lang.toUpperCase();
    $('#btn-lang').title = t('ui_lang');
    $('#btn-motion').textContent = reduced() ? '◌' : '✦';
    $('#btn-motion').title = reduced() ? t('motion_on') : t('motion_off');
    $('#btn-motion').setAttribute('aria-label', $('#btn-motion').title);
    var meta = document.querySelector('meta[name=theme-color]');
    if (meta) meta.setAttribute('content', theme === 'dark' ? '#0b0620' : '#f4f0ff');
  }
  $('#btn-theme').addEventListener('click', function () {
    var cur = document.documentElement.getAttribute('data-theme');
    S.theme = cur === 'dark' ? 'light' : 'dark'; store.set('theme', S.theme); applyPrefs();
  });
  $('#btn-lang').addEventListener('click', function () {
    S.lang = S.lang === 'de' ? 'en' : 'de'; store.set('lang', S.lang); applyPrefs(); S.screenKey = ''; route(true);
  });
  $('#btn-motion').addEventListener('click', function () {
    S.motion = reduced() ? 'full' : 'reduce'; store.set('motion', S.motion); applyPrefs();
  });
  $('#brand').addEventListener('click', function () {
    if (S.state && S.state.phase !== 'final' && S.state.phase !== 'lobby') { confirmLeave(); return; }
    goHome();
  });

  // ------------------------------------------------------------------ Parallaxe & 3D-Neigung
  (function parallax() {
    var layers = Array.prototype.slice.call(document.querySelectorAll('.bg-layer'));
    var tx = 0, ty = 0, cx = 0, cy = 0, raf = null;
    function loop() {
      cx += (tx - cx) * 0.08; cy += (ty - cy) * 0.08;
      layers.forEach(function (l) {
        var d = parseFloat(l.getAttribute('data-depth') || '0.2');
        l.style.transform = 'translate3d(' + (cx * d * 40).toFixed(2) + 'px,' + (cy * d * 40).toFixed(2) + 'px,0)';
      });
      raf = (Math.abs(tx - cx) + Math.abs(ty - cy) > 0.001) ? requestAnimationFrame(loop) : null;
    }
    function kick() { if (!raf && !reduced()) raf = requestAnimationFrame(loop); }
    window.addEventListener('pointermove', function (e) {
      if (reduced()) return;
      tx = e.clientX / window.innerWidth - 0.5; ty = e.clientY / window.innerHeight - 0.5; kick();
      var card = e.target.closest ? e.target.closest('.tilt') : null;
      if (card) {
        var r = card.getBoundingClientRect();
        var px = (e.clientX - r.left) / r.width - 0.5, py = (e.clientY - r.top) / r.height - 0.5;
        card.style.transform = 'perspective(900px) rotateX(' + (-py * 6).toFixed(2) + 'deg) rotateY(' + (px * 8).toFixed(2) + 'deg)';
      }
    }, { passive: true });
    document.addEventListener('pointerout', function (e) {
      var card = e.target.closest ? e.target.closest('.tilt') : null;
      if (card && !card.contains(e.relatedTarget)) card.style.transform = '';
    });
    window.addEventListener('deviceorientation', function (e) {
      if (reduced() || e.gamma === null) return;
      tx = Math.max(-0.5, Math.min(0.5, e.gamma / 60)); ty = Math.max(-0.5, Math.min(0.5, (e.beta - 40) / 80)); kick();
    }, { passive: true });
  })();

  // ------------------------------------------------------------------ Effekte (Konfetti)
  var fx = (function () {
    var cv = document.getElementById('fx'), ctx = cv.getContext('2d'), parts = [], raf = null;
    function size() { cv.width = window.innerWidth * (window.devicePixelRatio || 1); cv.height = window.innerHeight * (window.devicePixelRatio || 1); }
    window.addEventListener('resize', size); size();
    function frame() {
      ctx.clearRect(0, 0, cv.width, cv.height);
      var dpr = window.devicePixelRatio || 1;
      parts = parts.filter(function (p) { return p.life > 0; });
      parts.forEach(function (p) {
        p.vy += 0.18; p.vx *= 0.99; p.x += p.vx; p.y += p.vy; p.r += p.vr; p.life--;
        ctx.save(); ctx.translate(p.x * dpr, p.y * dpr); ctx.rotate(p.r);
        ctx.globalAlpha = Math.min(1, p.life / 40); ctx.fillStyle = p.c;
        ctx.fillRect(-p.s * dpr / 2, -p.s * dpr / 4, p.s * dpr, p.s * dpr / 2); ctx.restore();
      });
      raf = parts.length ? requestAnimationFrame(frame) : (ctx.clearRect(0, 0, cv.width, cv.height), null);
    }
    return {
      burst: function (n, x, y) {
        if (reduced()) return;
        var cols = ['#a78bfa', '#f472b6', '#22d3ee', '#facc15', '#34d399', '#fb7185'];
        x = x === undefined ? window.innerWidth / 2 : x; y = y === undefined ? window.innerHeight / 3 : y;
        for (var i = 0; i < n; i++) {
          var a = Math.random() * Math.PI * 2, sp = 4 + Math.random() * 9;
          parts.push({ x: x, y: y, vx: Math.cos(a) * sp, vy: Math.sin(a) * sp - 5, r: Math.random() * 6, vr: (Math.random() - 0.5) * 0.3, s: 6 + Math.random() * 8, c: cols[i % cols.length], life: 90 + Math.random() * 60 });
        }
        if (!raf) raf = requestAnimationFrame(frame);
      }
    };
  })();

  // ------------------------------------------------------------------ Modal
  function modal(title, body, actions) {
    var root = document.getElementById('modal-root');
    clear(root);
    function close() { clear(root); document.removeEventListener('keydown', onKey); }
    function onKey(e) { if (e.key === 'Escape') close(); }
    var btns = (actions || [{ label: t('ok') }]).map(function (a) {
      return h('button', { class: 'btn ' + (a.primary ? 'btn-primary' : 'btn-ghost'), type: 'button', onclick: function () { close(); if (a.fn) a.fn(); } }, a.label);
    });
    var box = h('div', { class: 'modal', role: 'dialog', 'aria-modal': 'true', 'aria-label': title },
      h('h2', { text: title }), h('div', { class: 'modal-body' }, body), h('div', { class: 'modal-actions' }, btns));
    root.appendChild(h('div', { class: 'modal-backdrop', onclick: function (e) { if (e.target === e.currentTarget) close(); } }, box));
    document.addEventListener('keydown', onKey);
    var f = box.querySelector('.btn-primary') || box.querySelector('button'); if (f) f.focus();
    return close;
  }

  // ------------------------------------------------------------------ Routing
  function stopPolling() { clearTimeout(S.pollT); S.pollT = null; clearInterval(S.tickT); S.tickT = null; }
  function goHome() {
    stopPolling(); S.sess = null; S.state = null; S.v = 0; S.screenKey = ''; setUrl(null); renderHome();
  }

  function route() {
    var params = new URLSearchParams(location.search);
    var code = (params.get('r') || '').toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 5);
    if (S.sess && S.state) { S.screenKey = ''; render(); return; }
    if (code) {
      var s = sessions()[code];
      if (s) { enterRoom(s); return; }
      renderJoin(code);
      return;
    }
    renderHome();
  }

  function enterRoom(sess) {
    S.sess = sess; S.v = 0; S.state = null; S.screenKey = '';
    setUrl(sess.code);
    showLoading(t('connecting'));
    poll(true);
  }

  function showLoading(text) {
    clear(app);
    app.appendChild(h('section', { class: 'screen center' }, h('div', { class: 'loader' }, h('span'), h('span'), h('span')), h('p', { class: 'muted', text: text })));
  }

  // ------------------------------------------------------------------ Startseite
  function renderHome() {
    clear(app);
    var configured = document.body.getAttribute('data-configured') === '1';
    var best = store.get('best', {});
    var bestVals = Object.keys(best).map(function (k) { return best[k]; });
    var top = bestVals.length ? Math.max.apply(null, bestVals.map(function (b) { return b.score; })) : null;
    var hero = h('section', { class: 'screen home' },
      h('div', { class: 'hero' },
        h('div', { class: 'hero-badge', text: t('tagline_badge') }),
        h('h1', { class: 'hero-title' }, h('span', { class: 'glitch', 'data-text': 'YouGBT', text: 'YouGBT' })),
        h('p', { class: 'hero-sub', text: t('tagline') }),
        h('div', { class: 'hero-chat', 'aria-hidden': 'true' },
          h('div', { class: 'bubble in demo d1' }, h('b', { text: 'Brötchen-Bernd' }), ' ', t('demo_q1')),
          h('div', { class: 'bubble out demo d2', text: t('demo_a1') }),
          h('div', { class: 'score-chip demo d3', text: '87 / 100' })
        )
      ),
      !configured ? h('div', { class: 'alert alert-err', text: t('not_configured_home') }) : null,
      h('div', { class: 'home-actions' },
        bigButton('🎮', t('play_solo'), t('play_solo_sub'), function () { renderCreate(true); }, !configured),
        bigButton('🛸', t('create_room'), t('create_room_sub'), function () { renderCreate(false); }, !configured),
        bigButton('🔑', t('join_room'), t('join_room_sub'), function () { renderJoin(''); }, false)
      ),
      top !== null ? h('p', { class: 'best-line' }, '🏆 ', t('personal_best'), ': ', h('b', { text: String(top) })) : null,
      h('div', { class: 'how' },
        howCard('1', t('how1_t'), t('how1')), howCard('2', t('how2_t'), t('how2')), howCard('3', t('how3_t'), t('how3'))
      )
    );
    app.appendChild(hero);
  }
  function bigButton(icon, title, sub, fn, disabled) {
    return h('button', { class: 'big-btn tilt', type: 'button', onclick: fn, disabled: disabled },
      h('span', { class: 'big-icon', text: icon }), h('span', { class: 'big-title', text: title }), h('span', { class: 'big-sub', text: sub }));
  }
  function howCard(n, title, text) {
    return h('div', { class: 'how-card glass' }, h('span', { class: 'how-n', text: n }), h('h3', { text: title }), h('p', { text: text }));
  }

  // ------------------------------------------------------------------ Erstellen / Beitreten
  function chipGroup(name, options, value, onChange, disabled) {
    var wrap = h('div', { class: 'chips', role: 'radiogroup', 'aria-label': name });
    options.forEach(function (o) {
      var b = h('button', { type: 'button', class: 'chip' + (String(o.v) === String(value) ? ' on' : ''), role: 'radio', 'aria-checked': String(String(o.v) === String(value)), disabled: disabled },
        o.icon ? h('span', { class: 'chip-icon', text: o.icon }) : null, o.label);
      b.addEventListener('click', function () {
        Array.prototype.forEach.call(wrap.children, function (c) { c.classList.remove('on'); c.setAttribute('aria-checked', 'false'); });
        b.classList.add('on'); b.setAttribute('aria-checked', 'true'); onChange(o.v);
      });
      wrap.appendChild(b);
    });
    return wrap;
  }

  function settingsEditor(cur, onChange, opts) {
    var s = Object.assign({}, cur);
    var disabled = opts && opts.readonly;
    function set(k, v) { s[k] = v; onChange(Object.assign({}, s)); }
    var maxOut = h('output', { class: 'range-val', text: String(s.max_players) });
    return h('div', { class: 'settings' },
      opts && opts.solo ? null : h('label', { class: 'field' }, h('span', { text: t('max_players') }),
        h('div', { class: 'range-row' },
          h('input', { type: 'range', min: String(Math.max(2, (opts && opts.minPlayers) || 2)), max: '10', value: String(s.max_players), disabled: disabled, oninput: function (e) { maxOut.textContent = e.target.value; }, onchange: function (e) { set('max_players', parseInt(e.target.value, 10)); } }),
          maxOut)),
      h('div', { class: 'field' }, h('span', { text: t('rounds') }),
        chipGroup(t('rounds'), [3, 5, 11, 21].map(function (n) { return { v: n, label: String(n) }; }), s.rounds, function (v) { set('rounds', v); }, disabled)),
      h('div', { class: 'field' }, h('span', { text: t('mode') }),
        chipGroup(t('mode'), [{ v: 'normal', label: t('mode_normal'), icon: '💬' }, { v: 'roulette', label: t('mode_roulette'), icon: '🎡' }], s.mode, function (v) { set('mode', v); }, disabled),
        h('small', { class: 'muted', text: s.mode === 'roulette' ? t('mode_roulette_desc') : t('mode_normal_desc') })),
      h('div', { class: 'field' }, h('span', { text: t('game_lang') }),
        chipGroup(t('game_lang'), [{ v: 'de', label: 'Deutsch' }, { v: 'en', label: 'English' }], s.lang, function (v) { set('lang', v); }, disabled))
    );
  }

  function nameField(id) {
    var last = store.get('name', '');
    var input = h('input', { id: id, class: 'input', maxlength: '16', required: true, autocomplete: 'nickname', placeholder: t('name_ph'), value: last });
    var preview = h('span', { class: 'name-preview' });
    function upd() { var v = input.value.trim().replace(/\s+AI$/i, ''); preview.textContent = v ? v + ' AI' : ''; }
    input.addEventListener('input', upd); upd();
    return { el: h('label', { class: 'field' }, h('span', { text: t('your_name') }), input, h('small', { class: 'muted' }, t('name_hint'), ' ', preview)), input: input };
  }

  function renderCreate(solo) {
    clear(app);
    var settings = { rounds: 5, mode: 'normal', lang: S.lang, max_players: 6 };
    var nf = nameField('name');
    var box = h('div', { class: 'settings-box' });
    function drawSettings() { clear(box); box.appendChild(settingsEditor(settings, function (s) { settings = s; drawSettings(); }, { solo: solo })); }
    drawSettings();
    var btn = h('button', { class: 'btn btn-primary btn-lg', type: 'submit', text: solo ? t('start_solo') : t('create_room') });
    var form = h('form', { class: 'card glass form-card', onsubmit: function (e) {
      e.preventDefault();
      btn.disabled = true; btn.classList.add('loading');
      store.set('name', nf.input.value.trim());
      api('create', { name: nf.input.value, solo: solo, settings: settings }).then(function (res) {
        btn.disabled = false; btn.classList.remove('loading');
        if (!res.ok) { toast(errMsg(res.error), 'err'); return; }
        saveSession(res.code, res.pid, res.token);
        enterRoom({ code: res.code, pid: res.pid, token: res.token });
      });
    } },
      h('h1', { class: 'title-sm', text: solo ? t('play_solo') : t('create_room') }),
      nf.el, box,
      solo ? h('p', { class: 'muted small', text: t('solo_info') }) : null,
      h('div', { class: 'row' }, h('button', { class: 'btn btn-ghost', type: 'button', onclick: goHome, text: t('back') }), btn)
    );
    app.appendChild(h('section', { class: 'screen narrow' }, form));
    nf.input.focus();
  }

  function renderJoin(code) {
    clear(app);
    var nf = nameField('jname');
    var codeIn = h('input', { class: 'input code-input', maxlength: '5', required: true, autocomplete: 'off', autocapitalize: 'characters', spellcheck: 'false', placeholder: 'ABCDE', value: code || '', inputmode: 'text', 'aria-label': t('room_code') });
    codeIn.addEventListener('input', function () { codeIn.value = codeIn.value.toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 5); });
    var info = h('p', { class: 'muted small' });
    var btn = h('button', { class: 'btn btn-primary btn-lg', type: 'submit', text: t('join') });
    if (code) {
      api('peek', { code: code }).then(function (res) {
        if (!res.ok) info.textContent = errMsg(res.error);
        else if (!res.joinable) info.textContent = res.phase === 'lobby' ? t('err_room_full') : t('err_game_running');
        else info.textContent = t('invite_ok');
      });
    }
    var form = h('form', { class: 'card glass form-card', onsubmit: function (e) {
      e.preventDefault();
      btn.disabled = true; btn.classList.add('loading');
      store.set('name', nf.input.value.trim());
      api('join', { code: codeIn.value, name: nf.input.value }).then(function (res) {
        btn.disabled = false; btn.classList.remove('loading');
        if (!res.ok) { toast(errMsg(res.error), 'err'); return; }
        saveSession(res.code, res.pid, res.token);
        enterRoom({ code: res.code, pid: res.pid, token: res.token });
      });
    } },
      h('h1', { class: 'title-sm', text: t('join_room') }),
      h('label', { class: 'field' }, h('span', { text: t('room_code') }), codeIn),
      nf.el, info,
      h('div', { class: 'row' }, h('button', { class: 'btn btn-ghost', type: 'button', onclick: goHome, text: t('back') }), btn)
    );
    app.appendChild(h('section', { class: 'screen narrow' }, form));
    (code ? nf.input : codeIn).focus();
  }

  // ------------------------------------------------------------------ Polling
  function pollDelay() {
    var st = S.state;
    var base = !st ? 1500 : ({ lobby: 2200, answering: 1500, grading: 1200, generating: 1200, spinning: 1500, reveal: 2000, stalled: 3000, final: 8000 })[st.phase] || 2000;
    if (document.hidden) base = Math.max(base, 6000);
    if (S.failCount) base = Math.min(15000, base * (1 + S.failCount));
    return base;
  }
  function schedulePoll(ms) { clearTimeout(S.pollT); S.pollT = setTimeout(poll, ms === undefined ? pollDelay() : ms); }

  function poll(first) {
    if (!S.sess) return;
    if (S.inflight) { schedulePoll(); return; }
    S.inflight = true;
    api('state', { v: first ? 0 : S.v }).then(function (res) {
      S.inflight = false;
      if (!S.sess) return;
      if (!res.ok) {
        if (res.error === 'room_not_found' || res.error === 'not_in_room') {
          dropSession(S.sess.code);
          stopPolling(); S.sess = null; S.state = null; setUrl(null);
          renderHome(); toast(errMsg(res.error), 'err');
          return;
        }
        S.failCount++;
        if (S.failCount === 2) toast(t('reconnecting'), 'warn');
        schedulePoll();
        return;
      }
      if (S.failCount >= 2) toast(t('reconnected'), 'ok');
      S.failCount = 0;
      if (res.same) { S.dueFlag = res.due; S.busyFlag = res.busy; }
      maybeAdvance(res.state ? res.state.due : res.due);
      schedulePoll();
    });
  }

  /** Nur wenn der Server einen KI-Schritt als fällig meldet – der Server stellt sicher, dass er einmal läuft. */
  function maybeAdvance(due) {
    if (!due || S.advancing || !S.sess) return;
    S.advancing = true;
    setTimeout(function () {
      api('advance').then(function (res) {
        S.advancing = false;
        if (!res.ok && res.error !== 'network') toast(errMsg(res.error), 'err');
        schedulePoll(300);
      });
    }, Math.random() * 700);
  }

  function acceptState(st, rtt) {
    S.offset = st.now - Date.now() + (rtt || 0) / 2;
    var prev = S.state;
    S.state = st; S.v = st.v;
    if (S.sess && st.me) saveSession(S.sess.code, S.sess.pid, S.sess.token);
    render(prev);
  }

  // ------------------------------------------------------------------ Hauptrendering
  function screenKeyOf(st) {
    var k = st.phase + '|' + (st.q ? st.q.key : '') + '|' + st.round + '|' + st.sub + '|' + st.me.status + '|' + S.lang;
    if (st.phase === 'answering' && st.my) k += '|' + st.my.submitted + '|' + (st.my.hint ? 1 : 0);
    if (st.phase === 'lobby') k += '|' + st.is_host + '|' + JSON.stringify(st.settings) + '|' + st.players.filter(function (p) { return p.status === 'active'; }).length;
    if (st.phase === 'reveal') k += '|' + (st.results ? st.results.list.length : 0) + '|' + st.me.ready;
    if (st.phase === 'stalled') k += '|' + st.is_host + '|' + (st.stalled && st.stalled.err);
    if (st.phase === 'generating' || st.phase === 'grading') k += '|' + (st.notice ? st.notice.type : '');
    return k;
  }

  function render(prev) {
    var st = S.state;
    if (!st) return;
    var key = screenKeyOf(st);
    if (key !== S.screenKey || !$('#game-shell')) {
      var ta = document.activeElement && document.activeElement.id === 'answer';
      S.screenKey = key;
      buildShell(st);
      if (ta && $('#answer')) $('#answer').focus();
    }
    updateDynamic(st, prev);
    if (!S.tickT) S.tickT = setInterval(tick, 250);
  }

  function buildShell(st) {
    clear(app);
    var main = h('div', { class: 'stage', id: 'stage' });
    var side = st.solo ? null : h('aside', { class: 'side' + (S.chatOpen ? ' open' : ''), id: 'side' },
      h('div', { class: 'side-tabs' },
        h('h2', { class: 'side-title', text: t('players') }),
        h('button', { class: 'icon-btn side-close', type: 'button', 'aria-label': t('close'), onclick: toggleSide, text: '✕' })),
      h('ul', { class: 'players', id: 'players' }),
      chatPanel(st));
    var hud = hudBar(st);
    var shell = h('section', { class: 'screen game' + (st.solo ? ' solo' : ''), id: 'game-shell' }, hud, h('div', { class: 'game-grid' }, main, side));
    app.appendChild(shell);
    if (!st.solo) {
      app.appendChild(h('button', { class: 'fab', id: 'fab', type: 'button', onclick: toggleSide, 'aria-label': t('players_chat') }, '💬', h('span', { class: 'fab-badge', id: 'fab-badge' })));
    }
    var fn = { lobby: stageLobby, spinning: stageSpin, generating: stageGenerating, answering: stageAnswer, grading: stageGrading, reveal: stageReveal, stalled: stageStalled, final: stageFinal }[st.phase];
    if (st.me.status !== 'active' && st.phase !== 'final') main.appendChild(stageEliminated(st));
    else if (fn) fn(main, st);
  }

  function toggleSide() {
    S.chatOpen = !S.chatOpen;
    var side = $('#side'); if (side) side.classList.toggle('open', S.chatOpen);
    if (S.chatOpen) { S.chatSeen = lastChatN(); updateBadge(); }
  }
  function lastChatN() { var c = S.state && S.state.chat; return c && c.length ? c[c.length - 1].n : 0; }
  function updateBadge() {
    var b = $('#fab-badge'); if (!b) return;
    var unread = (S.state.chat || []).filter(function (m) { return m.n > S.chatSeen && !m.sys && m.pid !== S.state.me.id; }).length;
    b.textContent = unread ? String(Math.min(unread, 99)) : '';
    b.style.display = unread ? 'grid' : 'none';
  }

  function hudBar(st) {
    var left = h('div', { class: 'hud-left' });
    if (st.phase === 'lobby') {
      left.appendChild(h('span', { class: 'hud-pill', text: st.solo ? t('solo') : t('lobby') }));
    } else if (st.phase !== 'final') {
      left.appendChild(h('span', { class: 'hud-pill', text: t('round_x', { x: st.round, y: st.settings.rounds }) }));
      if (st.special) left.appendChild(h('span', { class: 'hud-pill special', text: t('special_short', { x: st.sub + 1 }) }));
      if (st.category && st.phase !== 'spinning') left.appendChild(h('span', { class: 'hud-pill cat', text: st.category.label }));
    }
    var right = h('div', { class: 'hud-right' },
      st.phase !== 'lobby' ? h('span', { class: 'hud-score', id: 'hud-score' }) : null,
      h('span', { class: 'timer', id: 'timer', hidden: true },
        svgRing(), h('span', { class: 'timer-num', id: 'timer-num' })),
      st.phase !== 'final' ? h('button', { class: 'btn btn-ghost btn-sm', type: 'button', onclick: confirmLeave, title: st.solo ? t('end_solo') : t('leave'), 'aria-label': st.solo ? t('end_solo') : t('leave') },
        h('span', { class: 'hide-sm', text: st.solo ? t('end_solo') : t('leave') }), h('span', { class: 'show-sm', 'aria-hidden': 'true', text: '⏏' })) : null);
    return h('div', { class: 'hud' }, left, right);
  }
  function svgRing() {
    var ns = 'http://www.w3.org/2000/svg';
    var svg = document.createElementNS(ns, 'svg'); svg.setAttribute('viewBox', '0 0 44 44'); svg.setAttribute('class', 'ring');
    var bg = document.createElementNS(ns, 'circle'); bg.setAttribute('cx', '22'); bg.setAttribute('cy', '22'); bg.setAttribute('r', '19'); bg.setAttribute('class', 'ring-bg');
    var fg = document.createElementNS(ns, 'circle'); fg.setAttribute('cx', '22'); fg.setAttribute('cy', '22'); fg.setAttribute('r', '19'); fg.setAttribute('class', 'ring-fg'); fg.setAttribute('id', 'ring-fg');
    svg.appendChild(bg); svg.appendChild(fg); return svg;
  }

  function updateDynamic(st) {
    // Spielerliste
    var ul = $('#players');
    if (ul) {
      clear(ul);
      var list = st.players.slice().sort(function (a, b) { return (a.status === 'left') - (b.status === 'left') || b.score - a.score; });
      list.forEach(function (p) {
        var badge = null;
        if (p.status === 'left') badge = h('span', { class: 'badge out', text: t('eliminated') });
        else if (st.phase === 'answering') badge = p.submitted ? h('span', { class: 'badge ok', text: '✓ ' + t('submitted') }) : h('span', { class: 'badge wait', text: t('writing') });
        else if (st.phase === 'reveal') badge = p.ready ? h('span', { class: 'badge ok', text: t('ready') }) : null;
        ul.appendChild(h('li', { class: 'player' + (p.status === 'left' ? ' left' : '') + (p.id === st.me.id ? ' me' : '') },
          avatar(p.name, 'sm'),
          h('span', { class: 'p-name' }, h('span', { text: p.name }), p.host ? h('span', { class: 'crown', title: t('host'), text: ' 👑' }) : null),
          h('span', { class: 'dot ' + (p.online ? 'on' : 'off'), title: p.online ? t('online') : t('offline') }),
          badge,
          st.phase !== 'lobby' ? h('span', { class: 'p-score', text: String(p.score) }) : null));
      });
      if (st.phase === 'lobby') {
        var free = st.settings.max_players - st.players.filter(function (p) { return p.status === 'active'; }).length;
        for (var i = 0; i < free; i++) ul.appendChild(h('li', { class: 'player empty' }, h('span', { class: 'avatar sm ghost' }), h('span', { class: 'p-name muted', text: t('free_slot') })));
      }
    }
    // Chat
    renderChat(st);
    // Punkte oben
    var me = st.players.filter(function (p) { return p.id === st.me.id; })[0];
    var hs = $('#hud-score'); if (hs && me) hs.textContent = '⚡ ' + me.score;
    // Warte-Zähler
    var wc = $('#wait-count');
    if (wc) {
      var act = st.players.filter(function (p) { return p.status === 'active'; });
      wc.textContent = t('submitted_count', { x: act.filter(function (p) { return p.submitted; }).length, y: act.length });
    }
    var rc = $('#ready-count');
    if (rc) {
      var a2 = st.players.filter(function (p) { return p.status === 'active'; });
      rc.textContent = t('ready_count', { x: a2.filter(function (p) { return p.ready; }).length, y: a2.length });
    }
    var busy = $('#busy-text'); if (busy) busy.hidden = !st.busy;
    tick();
  }

  // Timer (synchron zur Serverzeit)
  function tick() {
    var st = S.state; if (!st) return;
    var timer = $('#timer'), num = $('#timer-num'), ring = $('#ring-fg');
    var deadline = null, total = 0;
    if (st.phase === 'answering' && st.q && st.q.deadline) { deadline = st.q.deadline; total = st.q.deadline - st.q.started; }
    if (st.phase === 'reveal' && st.results && st.results.reveal_until) {
      var rt = $('#reveal-timer');
      if (rt) rt.textContent = t('auto_next', { x: Math.max(0, Math.ceil((st.results.reveal_until - serverNow()) / 1000)) });
    }
    if (timer) {
      if (!deadline) { timer.hidden = true; }
      else {
        var left = Math.max(0, deadline - serverNow());
        var sec = Math.ceil(left / 1000);
        timer.hidden = false;
        num.textContent = String(sec);
        timer.classList.toggle('warn', sec <= 20);
        timer.classList.toggle('crit', sec <= 10);
        var c = 2 * Math.PI * 19;
        ring.style.strokeDasharray = c.toFixed(1);
        ring.style.strokeDashoffset = (c * (1 - left / total)).toFixed(1);
        if (left <= 0 && !S.expiredPolled) { S.expiredPolled = true; schedulePoll(YG_GRACE()); }
        if (left > 0) S.expiredPolled = false;
        var send = $('#send-btn');
        if (send && left <= 0 && !st.my.submitted) { send.disabled = true; }
      }
    }
  }
  function YG_GRACE() { return 2800; }

  // ------------------------------------------------------------------ Chat
  var EMOJIS = ['😂', '🔥', '🤖', '👀', '💀', '🧠', '👏', '😭', '🤯', '🫡'];
  function chatPanel(st) {
    var input = h('input', { class: 'input', id: 'chat-in', maxlength: '200', autocomplete: 'off', placeholder: t('chat_ph'), 'aria-label': t('chat') });
    var form = h('form', { class: 'chat-form', onsubmit: function (e) {
      e.preventDefault();
      var v = input.value.trim(); if (!v) return;
      input.disabled = true;
      api('chat', { text: v }).then(function (res) {
        input.disabled = false; input.focus();
        if (!res.ok) { toast(errMsg(res.error), 'err'); return; }
        input.value = '';
      });
    } }, input, h('button', { class: 'btn btn-primary btn-sm', type: 'submit', 'aria-label': t('send'), text: '➤' }));
    var emo = h('div', { class: 'emoji-bar' }, EMOJIS.map(function (e) {
      return h('button', { type: 'button', class: 'emoji', 'aria-label': e, onclick: function () { input.value = (input.value + e).slice(0, 200); input.focus(); } }, e);
    }));
    return h('div', { class: 'chat' },
      h('h2', { class: 'side-title', text: t('chat') }),
      h('div', { class: 'chat-log', id: 'chat-log', role: 'log', 'aria-live': 'polite' }),
      h('p', { class: 'chat-paused', id: 'chat-paused', hidden: true, text: t('chat_paused') }),
      emo, form);
  }
  function renderChat(st) {
    var log = $('#chat-log'); if (!log) return;
    var lastShown = parseInt(log.getAttribute('data-last') || '0', 10);
    var atBottom = log.scrollHeight - log.scrollTop - log.clientHeight < 40;
    (st.chat || []).forEach(function (m) {
      if (m.n <= lastShown) return;
      var el;
      if (m.sys) {
        el = h('div', { class: 'chat-sys', text: t('sys_' + m.sys, { name: m.name || '' }) });
      } else {
        el = h('div', { class: 'chat-msg' + (m.pid === st.me.id ? ' mine' : '') },
          h('span', { class: 'chat-name', style: '--h:' + hueOf(m.name), text: m.name }), h('span', { class: 'chat-text', text: m.text }));
      }
      log.appendChild(el);
      lastShown = m.n;
    });
    log.setAttribute('data-last', String(lastShown));
    while (log.children.length > 60) log.removeChild(log.firstChild);
    if (atBottom || !log.getAttribute('data-init')) { log.scrollTop = log.scrollHeight; log.setAttribute('data-init', '1'); }
    var paused = !!st.chat_paused || st.me.status !== 'active';
    var p = $('#chat-paused'); if (p) { p.hidden = !st.chat_paused; }
    var ci = $('#chat-in'); if (ci) { ci.disabled = paused; }
    var fb = document.querySelectorAll('.chat-form button, .emoji'); Array.prototype.forEach.call(fb, function (b) { b.disabled = paused; });
    if (S.chatOpen || window.innerWidth >= 1000) S.chatSeen = lastChatN();
    updateBadge();
  }

  // ------------------------------------------------------------------ Phasen
  function stageLobby(main, st) {
    var link = inviteLink(st.code);
    var codeBox = st.solo ? null : h('div', { class: 'code-card glass tilt' },
      h('span', { class: 'muted small', text: t('room_code') }),
      h('div', { class: 'room-code', text: st.code }),
      h('div', { class: 'row wrap' },
        h('button', { class: 'btn btn-ghost btn-sm', type: 'button', onclick: function () { copy(st.code); }, text: t('copy_code') }),
        h('button', { class: 'btn btn-ghost btn-sm', type: 'button', onclick: function () {
          if (navigator.share) navigator.share({ title: 'YouGBT', text: t('share_text'), url: link }).catch(function () {});
          else copy(link);
        }, text: t('share_link') })));
    var cur = st.settings;
    var active = st.players.filter(function (p) { return p.status === 'active'; }).length;
    var editor = settingsEditor(cur, function (s) {
      api('settings', { settings: s }).then(function (res) { if (!res.ok) toast(errMsg(res.error), 'err'); });
    }, { readonly: !st.is_host, solo: st.solo, minPlayers: active });
    var startBtn = st.is_host ? h('button', { class: 'btn btn-primary btn-lg pulse', type: 'button', disabled: !st.solo && active < 2, onclick: function (e) {
      var b = e.currentTarget; b.disabled = true; b.classList.add('loading');
      api('start').then(function (res) { b.disabled = false; b.classList.remove('loading'); if (!res.ok) toast(errMsg(res.error), 'err'); });
    }, text: st.solo ? t('start_solo') : t('start_game') }) : h('p', { class: 'muted center-text', text: t('wait_host') });
    main.appendChild(h('div', { class: 'lobby' },
      codeBox,
      h('div', { class: 'card glass' }, h('h2', { text: t('settings') }), editor,
        !st.is_host ? h('p', { class: 'muted small', text: t('host_sets') }) : null),
      h('div', { class: 'rules card glass' }, h('h2', { text: t('rules_t') }), h('ul', { class: 'rule-list' },
        h('li', { text: t('rule_time') }), h('li', { text: t('rule_special') }), h('li', { text: t('rule_hint') }), h('li', { text: t('rule_joker') }))),
      !st.solo && active < 2 ? h('p', { class: 'muted center-text', text: t('need_players') }) : null,
      startBtn));
  }

  function copy(text) {
    function ok() { toast(t('copied'), 'ok'); }
    if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(text).then(ok, function () { prompt(t('copy_manual'), text); });
    else { window.prompt(t('copy_manual'), text); }
  }

  function personaHead(st) {
    var p = st.persona || { name: '???', trait: '' };
    return h('div', { class: 'persona' + (st.special ? ' annoying' : '') }, avatar(p.name, 'lg'),
      h('div', null, h('div', { class: 'persona-name', text: p.name }), h('div', { class: 'persona-trait muted', text: p.trait }),
        st.special ? h('div', { class: 'persona-tag', text: t('annoying_asker') }) : null));
  }

  function chatThread(st, typeIt) {
    var th = h('div', { class: 'thread' });
    (st.thread || []).forEach(function (m, i) {
      th.appendChild(h('div', { class: 'bubble in old' }, h('span', { class: 'bubble-step', text: t('part_x', { x: i + 1 }) }), aiText(m)));
    });
    var bubble = h('div', { class: 'bubble in main-q' });
    if (st.special) bubble.appendChild(h('span', { class: 'bubble-step', text: t('part_x', { x: st.sub + 1 }) }));
    var txt = h('span', { class: 'q-text' });
    bubble.appendChild(txt);
    th.appendChild(bubble);
    var full = aiText(st.q.message);
    if (typeIt && !S.typed[st.q.key] && !reduced()) {
      S.typed[st.q.key] = true;
      var i = 0, step = Math.max(1, Math.ceil(full.length / 45));
      txt.classList.add('typing');
      var iv = setInterval(function () {
        i += step; txt.textContent = full.slice(0, i);
        if (i >= full.length) { clearInterval(iv); txt.classList.remove('typing'); }
      }, 24);
    } else { txt.textContent = full; S.typed[st.q.key] = true; }
    return th;
  }

  function stageSpin(main, st) {
    var n = st.wheel.length, seg = 360 / n;
    var ns = 'http://www.w3.org/2000/svg';
    var svg = document.createElementNS(ns, 'svg'); svg.setAttribute('viewBox', '-110 -110 220 220'); svg.setAttribute('class', 'wheel-svg');
    var g = document.createElementNS(ns, 'g'); g.setAttribute('class', 'wheel-rot');
    st.wheel.forEach(function (c, i) {
      var a0 = (i * seg - 90) * Math.PI / 180, a1 = ((i + 1) * seg - 90) * Math.PI / 180;
      var path = document.createElementNS(ns, 'path');
      path.setAttribute('d', 'M0 0 L' + (100 * Math.cos(a0)).toFixed(2) + ' ' + (100 * Math.sin(a0)).toFixed(2) + ' A100 100 0 0 1 ' + (100 * Math.cos(a1)).toFixed(2) + ' ' + (100 * Math.sin(a1)).toFixed(2) + ' Z');
      path.setAttribute('fill', 'hsl(' + ((i * 360 / n + 260) % 360) + ' 85% ' + (i % 2 ? '58%' : '48%') + ')');
      g.appendChild(path);
      var tx = document.createElementNS(ns, 'text');
      var mid = (i + 0.5) * seg - 90;
      tx.setAttribute('transform', 'rotate(' + mid + ') translate(58 0)');
      tx.setAttribute('class', 'wheel-label');
      tx.setAttribute('text-anchor', 'middle');
      tx.setAttribute('dominant-baseline', 'middle');
      tx.textContent = c.label.length > 14 ? c.label.slice(0, 13) + '…' : c.label;
      g.appendChild(tx);
    });
    var hub = document.createElementNS(ns, 'circle'); hub.setAttribute('r', '16'); hub.setAttribute('class', 'wheel-hub');
    svg.appendChild(g); svg.appendChild(hub);
    var label = h('div', { class: 'wheel-result', id: 'wheel-result' });
    main.appendChild(h('div', { class: 'spin-stage' }, h('h2', { class: 'center-text', text: t('roulette_title') }),
      h('div', { class: 'wheel' }, h('div', { class: 'wheel-pointer' }), svg), label));
    var target = st.spin.turns * 360 + (360 - (st.spin.idx + 0.5) * seg);
    var remain = st.spin.until - serverNow();
    function done() { label.textContent = '🎯 ' + st.wheel[st.spin.idx].label; label.classList.add('show'); }
    if (reduced() || remain < 400) { g.style.transform = 'rotate(' + target + 'deg)'; done(); return; }
    g.style.transform = 'rotate(0deg)';
    requestAnimationFrame(function () {
      requestAnimationFrame(function () {
        g.style.transition = 'transform ' + ((remain - 300) / 1000).toFixed(2) + 's cubic-bezier(.12,.72,.1,1)';
        g.style.transform = 'rotate(' + target + 'deg)';
      });
    });
    setTimeout(done, remain - 250);
  }

  function noticeBox(st) {
    if (!st.notice || st.notice.type !== 'annulled') return null;
    return h('div', { class: 'alert alert-warn' }, h('b', { text: t('annulled_t') }), ' ', t('annulled'),
      st.notice.reason ? h('div', { class: 'small muted', text: t('reason') + ': ' + st.notice.reason }) : null);
  }

  function stageGenerating(main, st) {
    main.appendChild(h('div', { class: 'wait-stage' },
      noticeBox(st),
      st.special && st.sub === 0 ? h('div', { class: 'special-banner', text: t('special_intro') }) : null,
      h('div', { class: 'bubble in typing-bubble' }, h('span', { class: 'dots' }, h('i'), h('i'), h('i'))),
      h('p', { class: 'muted center-text', text: st.category ? t('generating_cat', { cat: st.category.label }) : t('generating') })));
  }

  function stageAnswer(main, st) {
    var my = st.my || {};
    var thread = h('div', { class: 'chat-stage' }, personaHead(st), chatThread(st, true));
    main.appendChild(thread);
    if (my.submitted) {
      thread.querySelector('.thread').appendChild(h('div', { class: 'bubble out' },
        my.joker ? h('span', { class: 'bubble-step joker', text: '🎲 ' + t('joker_active') }) : null,
        my.answer));
      main.appendChild(h('div', { class: 'wait-box glass' }, h('div', { class: 'loader small' }, h('span'), h('span'), h('span')),
        h('p', { text: st.solo ? t('grading_soon') : t('waiting_others') }), st.solo ? null : h('p', { class: 'muted', id: 'wait-count' })));
      return;
    }
    var key = st.q.key;
    var ta = h('textarea', { id: 'answer', class: 'answer', maxlength: '1500', rows: '6', placeholder: t('answer_ph'), 'aria-label': t('your_answer') });
    ta.value = S.draft[key] || '';
    var counter = h('span', { class: 'counter' });
    function upd() { S.draft = {}; S.draft[key] = ta.value; store.set('draft', S.draft); counter.textContent = ta.value.length + ' / 1500'; counter.classList.toggle('near', ta.value.length > 1350); sendBtn.disabled = !ta.value.trim(); }
    var sendBtn = h('button', { class: 'btn btn-primary', id: 'send-btn', type: 'button', text: t('send_answer') });
    ta.addEventListener('input', upd);
    ta.addEventListener('keydown', function (e) { if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); sendBtn.click(); } });

    // Hinweis
    var hintEl;
    var penalty = st.special ? 5 : 10;
    if (my.hint) {
      hintEl = h('div', { class: 'hint-shown' }, '💡 ', h('b', { text: my.hint }), h('span', { class: 'muted small', text: ' (−' + penalty + ' ' + t('points') + ')' }));
    } else {
      hintEl = h('button', { class: 'btn btn-ghost btn-sm', type: 'button', disabled: st.me.hint_used, title: t('hint_desc', { p: penalty }), onclick: function (e) {
        var b = e.currentTarget;
        modal(t('hint_t'), h('p', { text: t('hint_confirm', { p: penalty }) }), [
          { label: t('cancel') },
          { label: t('hint_use'), primary: true, fn: function () {
            b.disabled = true;
            api('hint', { key: key }).then(function (res) { if (!res.ok) { b.disabled = false; toast(errMsg(res.error), 'err'); } });
          } }]);
      } }, '💡 ', st.me.hint_used ? t('hint_gone') : t('hint_btn', { p: penalty }));
    }
    // Risiko-Joker
    var jokerEl;
    if (st.special) {
      jokerEl = h('span', { class: 'joker-off small muted', text: '🎲 ' + t('joker_special_off') });
    } else if (st.me.joker_used) {
      jokerEl = h('span', { class: 'joker-off small muted', text: '🎲 ' + t('joker_gone') });
    } else {
      var on = !!S.joker[key];
      jokerEl = h('button', { class: 'btn btn-sm joker-btn' + (on ? ' on' : ''), type: 'button', 'aria-pressed': String(on), onclick: function () {
        if (S.joker[key]) { S.joker[key] = false; S.screenKey = ''; render(); return; }
        modal(t('joker_t'), h('div', null, h('p', { text: t('joker_rule', { x: 75 }) }), h('p', { class: 'muted small', text: t('joker_rule2') })), [
          { label: t('cancel') },
          { label: t('joker_activate'), primary: true, fn: function () { S.joker[key] = true; S.screenKey = ''; render(); } }]);
      } }, '🎲 ', on ? t('joker_on') : t('joker_btn'));
    }

    sendBtn.addEventListener('click', function () {
      var text = ta.value.trim(); if (!text) return;
      sendBtn.disabled = true; sendBtn.classList.add('loading'); ta.readOnly = true;
      api('submit', { key: key, answer: text, joker: !!S.joker[key] }).then(function (res) {
        if (!res.ok) {
          sendBtn.classList.remove('loading'); ta.readOnly = false; sendBtn.disabled = false;
          toast(errMsg(res.error), 'err');
          return;
        }
        S.draft = {}; store.set('draft', {});
      });
    });

    main.appendChild(h('div', { class: 'composer glass' },
      h('div', { class: 'composer-top' }, hintEl, jokerEl),
      S.joker[key] ? h('p', { class: 'joker-note', text: t('joker_note', { x: 75 }) }) : null,
      ta,
      h('div', { class: 'composer-bottom' }, counter, h('span', { class: 'muted small hide-sm', text: t('ctrl_enter') }), sendBtn),
      h('p', { class: 'muted small', text: st.solo ? t('solo_no_timer') : t('final_note') })));
    upd();
  }

  function stageGrading(main, st) {
    var thread = h('div', { class: 'chat-stage' }, personaHead(st), st.q ? chatThread(st, false) : null);
    main.appendChild(thread);
    if (st.my && st.my.submitted) thread.querySelector('.thread').appendChild(h('div', { class: 'bubble out', text: st.my.answer }));
    main.appendChild(h('div', { class: 'wait-box glass grading' },
      h('div', { class: 'scanner' }), h('p', { class: 'big-wait', text: t('grading') }), h('p', { class: 'muted', text: t('grading_sub') })));
  }

  function scoreBar(r) {
    var pct = Math.round(100 * r.points / (r.joker && r.joker_ok ? 200 : r.max));
    var bar = h('div', { class: 'bar' }, h('span', { class: 'bar-fill', style: '--w:' + Math.min(100, pct) + '%' }));
    return bar;
  }

  function stageReveal(main, st) {
    var res = st.results; if (!res) return;
    var me = st.me.id;
    var list = res.list.slice().sort(function (a, b) { return (b.id === me) - (a.id === me) || b.points - a.points; });
    var thread = h('div', { class: 'chat-stage compact' }, personaHead(st), chatThread(st, false));
    main.appendChild(thread);
    var cards = h('div', { class: 'results' });
    list.forEach(function (r, i) {
      var badges = h('div', { class: 'r-badges' },
        r.joker ? h('span', { class: 'badge ' + (r.joker_ok ? 'ok' : 'out'), text: '🎲 ' + (r.joker_ok ? t('joker_win') : t('joker_lose')) }) : null,
        r.hint ? h('span', { class: 'badge hint', text: '💡 ' + r.hint + ' (−' + r.penalty + ')' }) : null,
        r.perfect ? h('span', { class: 'badge perfect', text: '💯 ' + t('perfect_badge') }) : null);
      cards.appendChild(h('article', { class: 'r-card glass' + (r.id === me ? ' me' : '') + (r.perfect ? ' perfect' : ''), style: '--i:' + i },
        h('header', { class: 'r-head' }, avatar(r.name, 'sm'), h('span', { class: 'r-name', text: r.name }),
          h('span', { class: 'r-points' }, h('b', { text: '+' + r.points }), h('small', { text: ' ' + t('raw_of', { x: r.raw, y: 100 }) }))),
        scoreBar(r),
        r.none ? h('p', { class: 'muted r-answer', text: t('no_answer') }) : h('p', { class: 'r-answer', text: r.answer }),
        r.reason ? h('p', { class: 'r-reason' }, '🧑‍⚖️ ', r.reason) : null,
        badges));
    });
    var model = h('details', { class: 'model glass', open: true },
      h('summary', { text: '✅ ' + t('model_answer') }), h('p', { text: res.model_answer }),
      h('p', { class: 'muted small', text: t('criteria') + ': ' + res.criteria.join(' · ') }));
    main.appendChild(model);
    main.appendChild(cards);

    if (res.round_totals) main.appendChild(scoreboard(st, res.round_totals));
    else if (st.special) main.appendChild(h('p', { class: 'muted center-text', text: t('special_pending') }));

    var isLast = st.round >= st.settings.rounds && (!st.special || st.sub >= 2);
    var nextLabel = isLast ? t('to_final') : (st.special && st.sub < 2 ? t('next_followup') : t('next_round'));
    var actions = h('div', { class: 'reveal-actions' });
    if (!st.me.ready) {
      actions.appendChild(h('button', { class: 'btn btn-primary btn-lg', type: 'button', onclick: function (e) {
        e.currentTarget.disabled = true; api('ready').then(function (r) { if (!r.ok) toast(errMsg(r.error), 'err'); });
      }, text: st.solo ? nextLabel : t('ready_btn') + ' – ' + nextLabel }));
    } else {
      actions.appendChild(h('p', { class: 'muted', text: t('waiting_ready') }));
    }
    if (!st.solo) {
      actions.appendChild(h('p', { class: 'muted small', id: 'ready-count' }));
      actions.appendChild(h('p', { class: 'muted small', id: 'reveal-timer' }));
      if (st.is_host) actions.appendChild(h('button', { class: 'btn btn-ghost btn-sm', type: 'button', onclick: function () { api('ready', { force: true }); }, text: t('force_next') }));
    }
    main.appendChild(actions);

    // Perfekte 100: kurze Feier, einmal pro Auflösung
    var perfect = list.filter(function (r) { return r.perfect; });
    if (perfect.length && !S.celebrated[st.q.key]) {
      S.celebrated[st.q.key] = true;
      celebrate(perfect.map(function (r) { return r.name; }));
    }
  }

  function celebrate(names) {
    var el = h('div', { class: 'perfect-overlay', role: 'status' },
      h('div', { class: 'perfect-title', text: t('perfect_title') }),
      h('div', { class: 'perfect-num', text: '100' }),
      h('div', { class: 'perfect-names', text: names.join(' · ') }));
    document.body.appendChild(el);
    fx.burst(160);
    setTimeout(function () { fx.burst(90, window.innerWidth * 0.2, window.innerHeight * 0.5); fx.burst(90, window.innerWidth * 0.8, window.innerHeight * 0.5); }, 350);
    setTimeout(function () { el.classList.add('out'); }, reduced() ? 1800 : 2400);
    setTimeout(function () { el.remove(); }, 3000);
  }

  function scoreboard(st, totals) {
    var rows = st.players.slice().sort(function (a, b) { return (a.status === 'left') - (b.status === 'left') || b.score - a.score; });
    return h('div', { class: 'scoreboard glass' }, h('h3', { text: t('scoreboard') }),
      h('ol', null, rows.map(function (p) {
        return h('li', { class: p.status === 'left' ? 'left' : '' }, h('span', { class: 'sb-name', text: p.name }),
          totals[p.id] !== undefined ? h('span', { class: 'sb-plus', text: '+' + totals[p.id] }) : null,
          h('b', { class: 'sb-score', text: String(p.score) }));
      })));
  }

  function stageStalled(main, st) {
    var err = st.stalled ? st.stalled.err : 'ai_error';
    main.appendChild(h('div', { class: 'card glass stalled' },
      h('div', { class: 'stalled-icon', text: err === 'ai_limit' ? '🪫' : '📡' }),
      h('h2', { text: t('stalled_t') }),
      h('p', { text: errMsg(err) }),
      h('p', { class: 'muted small', text: t('stalled_safe') }),
      st.is_host ? h('div', { class: 'row wrap center' },
        h('button', { class: 'btn btn-primary', type: 'button', onclick: function (e) {
          e.currentTarget.disabled = true; api('retry').then(function (r) { if (!r.ok) toast(errMsg(r.error), 'err'); });
        }, text: t('retry') }),
        h('button', { class: 'btn btn-ghost', type: 'button', onclick: confirmEnd, text: t('end_game') }))
        : h('p', { class: 'muted', text: t('stalled_wait_host') })));
  }

  function stageEliminated(st) {
    return h('div', { class: 'card glass center-text' }, h('h2', { text: t('you_left') }), h('p', { class: 'muted', text: t('you_left_sub') }),
      h('button', { class: 'btn btn-primary', type: 'button', onclick: function () { dropSession(st.code); goHome(); }, text: t('home') }));
  }

  function stageFinal(main, st) {
    var ranking = st.final.ranking;
    var reason = st.final.reason;
    var head = h('div', { class: 'final-head' },
      h('h1', { class: 'final-title', text: reason === 'last_player' ? t('final_last') : t('final_title') }),
      reason === 'ended' ? h('p', { class: 'muted', text: t('final_ended') }) : null);
    main.appendChild(head);

    var active = ranking.filter(function (r) { return !r.left; });
    if (st.solo) {
      var score = ranking[0].score;
      var best = store.get('best', {});
      var k = String(st.settings.rounds);
      var prevBest = best[k] ? best[k].score : null;
      var isNew = prevBest === null || score > prevBest;
      if (isNew && !S.savedBest) { best[k] = { score: score, date: Date.now() }; store.set('best', best); }
      S.savedBest = true;
      main.appendChild(h('div', { class: 'solo-result glass tilt' },
        h('div', { class: 'muted', text: t('your_score') }),
        h('div', { class: 'solo-score', text: String(score) }),
        h('div', { class: 'muted', text: t('max_possible', { x: maxScore(st.settings.rounds) }) }),
        isNew ? h('div', { class: 'badge perfect', text: '🏆 ' + t('new_best') }) : h('div', { class: 'muted', text: t('best_is', { x: prevBest }) })));
      if (isNew) fx.burst(140);
    } else if (active.length >= 3 || ranking.length >= 3) {
      var top = ranking.slice(0, 3);
      var order = [top[1], top[0], top[2]];
      main.appendChild(h('div', { class: 'podium' }, order.map(function (r, i) {
        if (!r) return h('div', { class: 'pod empty' });
        var place = ['second', 'first', 'third'][i];
        return h('div', { class: 'pod ' + place, style: '--d:' + [0.6, 1.2, 0.2][i] + 's' },
          avatar(r.name, 'lg'), h('div', { class: 'pod-name', text: r.name }), h('div', { class: 'pod-score', text: String(r.score) }),
          h('div', { class: 'pod-block' }, h('span', { text: String(r.rank) })));
      })));
      fx.burst(180);
    } else {
      var w = ranking[0];
      var tie = ranking.length > 1 && ranking[1].rank === 1;
      main.appendChild(h('div', { class: 'duel glass' },
        tie ? h('div', { class: 'duel-title', text: '🤝 ' + t('tie') }) : h('div', { class: 'duel-title', text: '🏆 ' + t('winner_is', { name: w.name }) }),
        h('div', { class: 'duel-row' }, ranking.map(function (r) {
          return h('div', { class: 'duel-p' + (r.rank === 1 ? ' win' : '') }, avatar(r.name, 'lg'), h('b', { text: r.name }), h('span', { text: String(r.score) }));
        }))));
      fx.burst(150);
    }
    if (!st.solo) {
      main.appendChild(h('div', { class: 'ranking glass' }, h('h3', { text: t('ranking') }),
        h('ol', null, ranking.map(function (r) {
          return h('li', { class: (r.left ? 'left ' : '') + (r.id === st.me.id ? 'me' : '') },
            h('span', { class: 'rk', text: String(r.rank) + '.' }), h('span', { class: 'rk-name', text: r.name }),
            r.left ? h('span', { class: 'badge out', text: t('eliminated') }) : null,
            h('b', { class: 'rk-score', text: String(r.score) }));
        }))));
    }
    main.appendChild(h('div', { class: 'row wrap center' },
      h('button', { class: 'btn btn-primary btn-lg', type: 'button', onclick: function () { dropSession(st.code); goHome(); }, text: t('play_again') })));
  }

  function maxScore(rounds) {
    var s = 0; for (var i = 0; i < rounds; i++) s += ((i + 1) % 3 === 0) ? 150 : 100; return s;
  }

  function confirmLeave() {
    var st = S.state;
    if (!st || st.phase === 'final' || st.me.status !== 'active') { if (st) dropSession(st.code); goHome(); return; }
    modal(st.solo ? t('end_solo') : t('leave'), h('p', { text: st.solo ? t('end_solo_confirm') : t('leave_confirm') }), [
      { label: t('cancel') },
      { label: st.solo ? t('end_solo') : t('leave'), primary: true, fn: function () {
        var code = st.code;
        api('leave').then(function () { dropSession(code); goHome(); });
      } }]);
  }
  function confirmEnd() {
    modal(t('end_game'), h('p', { text: t('end_confirm') }), [
      { label: t('cancel') },
      { label: t('end_game'), primary: true, fn: function () { api('end').then(function (r) { if (!r.ok) toast(errMsg(r.error), 'err'); }); } }]);
  }

  // ------------------------------------------------------------------ Start
  document.addEventListener('visibilitychange', function () { if (!document.hidden && S.sess) schedulePoll(50); });
  window.addEventListener('online', function () { if (S.sess) schedulePoll(50); });
  if (mqReduce.addEventListener) mqReduce.addEventListener('change', applyPrefs);
  applyPrefs();
  route();
})();
