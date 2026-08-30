// Der Karten-Editor: Raster, Palette, Platzieren, Eigenschaften.
//
// Der Zustand ist die Kartenliste selbst (dasselbe Format wie mapRules.js
// prüft und das Spiel lädt) – jede Bedienung ändert diese Objekte, jede
// Anzeige liest daraus. Es gibt keinen zweiten Zustand im DOM.
//
// Alles auf einem Canvas, ohne Bibliothek: ein Raster, Rechtecke, Bilder.
// Feste Elemente (Boden, Plattform, Stacheln …) zieht man als Rechteck auf,
// alles andere sitzt mit einem Klick. Klick wählt aus, Ziehen verschiebt,
// Entf löscht.

import { ELEMENTS, ELEMENT_TYPES, cleanProps, defaultProps } from '../../src/game/elements.js';
import { MAP_LIMITS, slugify, validateMap } from '../../src/net/mapRules.js';
import { buildElementArt } from '../../src/render/elementArt.js';

const ELEMENT_SIZES = Object.fromEntries(
  Object.entries(ELEMENTS).map(([type, def]) => [type, def.cells]),
);

/** Kräftige Kennfarben je Typ – für Rahmen und Palette. */
const TYPE_COLOR = {
  ground: '#7a5a34',
  platform: '#4f8f4a',
  crumble: '#8d7f66',
  mover: '#b98a3e',
  spring: '#c3512c',
  spikes: '#c3281c',
  emerald: '#3fe3a8',
  key: '#f5c53a',
  door: '#8a5f2f',
  portal: '#9a4dd7',
  walker: '#d9330f',
  flyer: '#7b6bd9',
  checkpoint: '#5fb0d9',
  flag: '#14a072',
};

/**
 * @param {object} dom die Editor-Elemente aus admin/index.html
 * @param {{status:(t:string,bad?:boolean)=>void, onDirty:()=>void}} host
 */
export function createEditor(dom, host) {
  /** @type {object[]} die Karten, geteilt mit main.js */
  let maps = [];
  let current = 0;
  /** 'select' | 'erase' | 'spawn' | ein Element-Typ */
  let tool = 'select';
  let selectedId = null;
  /** Laufender Zug: {mode:'draw'|'move', ...} */
  let drag = null;
  let art = null;

  const canvas = dom.editorCanvas;
  const ctx = canvas.getContext('2d');

  const map = () => maps[current];
  const cellPx = () => map()?.cell ?? 16;
  const elementById = (id) => map()?.elements.find((e) => e.id === id);

  function newId(type) {
    let n = 1;
    while (map().elements.some((e) => e.id === `${type}-${n}`)) n += 1;
    return `${type}-${n}`;
  }

  function freshMap(name) {
    return {
      version: 1,
      id: slugify(name) + '-' + Math.random().toString(36).slice(2, 6),
      name,
      cols: 80,
      rows: 21,
      cell: 16,
      spawn: { x: 2, y: 17 },
      elements: [
        { id: 'boden-1', type: 'ground', x: 0, y: 19, w: 80, h: 2, props: {}, note: '' },
        { id: 'ziel-1', type: 'flag', x: 76, y: 16, w: 2, h: 3, props: {}, note: '' },
      ],
    };
  }

  // -------------------------------------------------------------------------
  // Zeichnen
  // -------------------------------------------------------------------------

  function draw() {
    const m = map();
    if (m === undefined) return;
    if (art === null) art = buildElementArt(ELEMENT_SIZES);
    const cell = m.cell;
    canvas.width = m.cols * cell;
    canvas.height = m.rows * cell;

    ctx.fillStyle = '#0d1a16';
    ctx.fillRect(0, 0, canvas.width, canvas.height);

    // Raster
    ctx.strokeStyle = 'rgba(143,163,131,0.15)';
    ctx.lineWidth = 1;
    ctx.beginPath();
    for (let x = 0; x <= m.cols; x += 1) {
      ctx.moveTo(x * cell + 0.5, 0);
      ctx.lineTo(x * cell + 0.5, canvas.height);
    }
    for (let y = 0; y <= m.rows; y += 1) {
      ctx.moveTo(0, y * cell + 0.5);
      ctx.lineTo(canvas.width, y * cell + 0.5);
    }
    ctx.stroke();

    // Elemente
    ctx.imageSmoothingEnabled = false;
    for (const e of m.elements) {
      const x = e.x * cell;
      const y = e.y * cell;
      const w = e.w * cell;
      const h = e.h * cell;
      const color = TYPE_COLOR[e.type] ?? '#888';

      const image = art.get(e.type) ?? null;
      if (ELEMENTS[e.type]?.solid !== undefined) {
        // Gekachelte Fläche
        ctx.fillStyle = color;
        ctx.globalAlpha = 0.5;
        ctx.fillRect(x, y, w, h);
        ctx.globalAlpha = 1;
      } else if (image !== null) {
        ctx.drawImage(image, x, y, w, h);
      } else {
        ctx.fillStyle = color;
        ctx.fillRect(x, y, w, h);
      }
      ctx.strokeStyle = color;
      ctx.lineWidth = e.id === selectedId ? 3 : 1;
      if (e.id === selectedId) ctx.strokeStyle = '#3fe3a8';
      ctx.strokeRect(x + 0.5, y + 0.5, w - 1, h - 1);

      // Typkürzel für Flächen, die kein Bild haben
      if (ELEMENTS[e.type]?.solid !== undefined && w >= 24) {
        ctx.fillStyle = 'rgba(255,255,255,0.7)';
        ctx.font = '10px ui-monospace, monospace';
        ctx.fillText(ELEMENTS[e.type].name.de, x + 3, y + 11);
      }
    }

    // Startpunkt
    ctx.fillStyle = '#dbe8cf';
    ctx.font = `${Math.max(10, cell - 4)}px ui-monospace, monospace`;
    ctx.fillText('▶', m.spawn.x * cell + 2, (m.spawn.y + 1) * cell - 3);
    ctx.strokeStyle = '#dbe8cf';
    ctx.strokeRect(m.spawn.x * cell + 0.5, m.spawn.y * cell + 0.5, cell - 1, cell - 1);

    // Zieh-Vorschau
    if (drag !== null && drag.mode === 'draw' && drag.rect !== null) {
      const r = drag.rect;
      ctx.strokeStyle = '#3fe3a8';
      ctx.setLineDash([4, 3]);
      ctx.strokeRect(r.x * cell + 0.5, r.y * cell + 0.5, r.w * cell - 1, r.h * cell - 1);
      ctx.setLineDash([]);
    }
  }

  // -------------------------------------------------------------------------
  // Palette und Eigenschaften
  // -------------------------------------------------------------------------

  function buildPalette() {
    dom.palette.replaceChildren();
    const tools = [
      ['select', 'Auswählen'],
      ['erase', 'Radierer'],
      ['spawn', 'Start setzen'],
    ];
    for (const [id, label] of tools) {
      dom.palette.append(paletteButton(id, label, '#8fa383'));
    }
    const rule = document.createElement('hr');
    rule.className = 'edit__rule';
    dom.palette.append(rule);
    for (const type of ELEMENT_TYPES) {
      dom.palette.append(paletteButton(type, ELEMENTS[type].name.de, TYPE_COLOR[type]));
    }
    markTool();
  }

  function paletteButton(id, label, color) {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'edit__tool';
    button.dataset.tool = id;
    const dot = document.createElement('span');
    dot.className = 'edit__dot';
    dot.style.background = color;
    button.append(dot, document.createTextNode(label));
    button.addEventListener('click', () => {
      tool = id;
      markTool();
    });
    return button;
  }

  function markTool() {
    for (const b of dom.palette.querySelectorAll('.edit__tool')) {
      b.classList.toggle('edit__tool--active', b.dataset.tool === tool);
    }
  }

  function buildProps() {
    dom.props.replaceChildren();
    const e = selectedId === null ? undefined : elementById(selectedId);
    if (e === undefined) {
      const hint = document.createElement('p');
      hint.className = 'note';
      hint.textContent = 'Nichts ausgewählt. Klick im Raster auf ein Element.';
      dom.props.append(hint);
      return;
    }
    const def = ELEMENTS[e.type];

    const title = document.createElement('h3');
    title.textContent = def.name.de;
    dom.props.append(title);

    for (const [key, spec] of Object.entries(def.props)) {
      const label = document.createElement('label');
      label.className = 'field';
      const span = document.createElement('span');
      span.className = 'field__label';
      span.textContent = spec.label.de;
      label.append(span);

      if (spec.kind === 'number') {
        const inp = document.createElement('input');
        inp.type = 'number';
        inp.min = String(spec.min);
        inp.max = String(spec.max);
        inp.step = String(spec.step);
        inp.value = String(e.props[key] ?? spec.def);
        inp.addEventListener('change', () => {
          e.props = cleanProps(e.type, { ...e.props, [key]: Number(inp.value) });
          inp.value = String(e.props[key]);
          dirty();
        });
        label.append(inp);
      } else if (spec.kind === 'bool') {
        const inp = document.createElement('input');
        inp.type = 'checkbox';
        inp.checked = e.props[key] === true;
        inp.addEventListener('change', () => {
          e.props = cleanProps(e.type, { ...e.props, [key]: inp.checked });
          dirty();
        });
        label.append(inp);
      } else if (spec.kind === 'choice') {
        const sel = document.createElement('select');
        for (const option of spec.options) {
          const o = document.createElement('option');
          o.value = option.value;
          o.textContent = option.label.de;
          sel.append(o);
        }
        sel.value = e.props[key] ?? spec.def;
        sel.addEventListener('change', () => {
          e.props = cleanProps(e.type, { ...e.props, [key]: sel.value });
          buildProps();
          dirty();
        });
        label.append(sel);
      } else if (spec.kind === 'target') {
        const sel = document.createElement('select');
        const none = document.createElement('option');
        none.value = '';
        none.textContent = '– keins –';
        sel.append(none);
        for (const other of map().elements) {
          if (other.type !== spec.type || other.id === e.id) continue;
          const o = document.createElement('option');
          o.value = other.id;
          o.textContent = `${ELEMENTS[other.type].name.de} bei ${other.x}/${other.y}`;
          sel.append(o);
        }
        sel.value = e.props[key] ?? '';
        sel.addEventListener('change', () => {
          e.props = cleanProps(e.type, { ...e.props, [key]: sel.value });
          dirty();
        });
        label.append(sel);
      }
      dom.props.append(label);
    }

    // Die Notiz: Freitext für den, der die Karte baut. Wird gespeichert und
    // angezeigt, aber nicht gedeutet (Absprache, siehe elements.js).
    const noteLabel = document.createElement('label');
    noteLabel.className = 'field';
    const noteSpan = document.createElement('span');
    noteSpan.className = 'field__label';
    noteSpan.textContent = 'Notiz (nur für dich)';
    const note = document.createElement('textarea');
    note.rows = 3;
    note.maxLength = MAP_LIMITS.noteMax;
    note.value = e.note ?? '';
    note.addEventListener('change', () => {
      e.note = note.value.slice(0, MAP_LIMITS.noteMax);
      dirty(false);
    });
    noteLabel.append(noteSpan, note);
    dom.props.append(noteLabel);

    const del = document.createElement('button');
    del.type = 'button';
    del.className = 'btn btn--danger';
    del.textContent = 'Element löschen';
    del.addEventListener('click', () => removeElement(e.id));
    dom.props.append(del);
  }

  // -------------------------------------------------------------------------
  // Mausarbeit im Raster
  // -------------------------------------------------------------------------

  function cellAt(event) {
    const rect = canvas.getBoundingClientRect();
    const scaleX = canvas.width / rect.width;
    const scaleY = canvas.height / rect.height;
    const x = Math.floor(((event.clientX - rect.left) * scaleX) / cellPx());
    const y = Math.floor(((event.clientY - rect.top) * scaleY) / cellPx());
    const m = map();
    return {
      x: Math.max(0, Math.min(m.cols - 1, x)),
      y: Math.max(0, Math.min(m.rows - 1, y)),
    };
  }

  function topElementAt(cell) {
    const list = map().elements;
    for (let i = list.length - 1; i >= 0; i -= 1) {
      const e = list[i];
      if (cell.x >= e.x && cell.x < e.x + e.w && cell.y >= e.y && cell.y < e.y + e.h) return e;
    }
    return undefined;
  }

  function removeElement(id) {
    const m = map();
    m.elements = m.elements.filter((e) => e.id !== id);
    if (selectedId === id) selectedId = null;
    buildProps();
    dirty();
  }

  function placeAt(type, cell) {
    const def = ELEMENTS[type];
    const m = map();
    if (def.unique && m.elements.some((e) => e.type === type)) {
      // Es kann nur eine geben: die vorhandene wird versetzt statt verdoppelt.
      const existing = m.elements.find((e) => e.type === type);
      existing.x = Math.min(cell.x, m.cols - existing.w);
      existing.y = Math.min(cell.y, m.rows - existing.h);
      selectedId = existing.id;
      buildProps();
      dirty();
      return;
    }
    const [w, h] = def.cells;
    const e = {
      id: newId(type),
      type,
      x: Math.min(cell.x, m.cols - w),
      y: Math.min(cell.y, m.rows - h),
      w,
      h,
      props: defaultProps(type),
      note: '',
    };
    m.elements.push(e);
    selectedId = e.id;
    buildProps();
    dirty();
  }

  function onDown(event) {
    if (map() === undefined) return;
    event.preventDefault();
    canvas.setPointerCapture?.(event.pointerId);
    const cell = cellAt(event);

    if (tool === 'spawn') {
      map().spawn = { x: cell.x, y: cell.y };
      dirty();
      return;
    }
    if (tool === 'erase') {
      const hit = topElementAt(cell);
      if (hit !== undefined) removeElement(hit.id);
      return;
    }
    if (tool === 'select') {
      const hit = topElementAt(cell);
      selectedId = hit?.id ?? null;
      buildProps();
      if (hit !== undefined) {
        drag = { mode: 'move', id: hit.id, fromX: cell.x - hit.x, fromY: cell.y - hit.y };
      }
      draw();
      return;
    }
    // Element-Werkzeug: aufziehbare als Rechteck-Zug, andere sofort setzen.
    if (ELEMENTS[tool]?.resizable === true) {
      drag = { mode: 'draw', start: cell, rect: { x: cell.x, y: cell.y, w: 1, h: 1 } };
      draw();
    } else {
      placeAt(tool, cell);
    }
  }

  function onMove(event) {
    if (drag === null) return;
    const cell = cellAt(event);
    if (drag.mode === 'draw') {
      const x = Math.min(drag.start.x, cell.x);
      const y = Math.min(drag.start.y, cell.y);
      drag.rect = {
        x,
        y,
        w: Math.abs(cell.x - drag.start.x) + 1,
        h: Math.abs(cell.y - drag.start.y) + 1,
      };
      draw();
    } else if (drag.mode === 'move') {
      const e = elementById(drag.id);
      if (e === undefined) return;
      const m = map();
      e.x = Math.max(0, Math.min(m.cols - e.w, cell.x - drag.fromX));
      e.y = Math.max(0, Math.min(m.rows - e.h, cell.y - drag.fromY));
      draw();
    }
  }

  function onUp() {
    if (drag === null) return;
    if (drag.mode === 'draw' && drag.rect !== null) {
      const def = ELEMENTS[tool];
      const m = map();
      const e = {
        id: newId(tool),
        type: tool,
        x: drag.rect.x,
        y: drag.rect.y,
        w: drag.rect.w,
        h: Math.max(def.cells[1], drag.rect.h),
        props: defaultProps(tool),
        note: '',
      };
      e.h = Math.min(e.h, m.rows - e.y);
      m.elements.push(e);
      selectedId = e.id;
      buildProps();
    }
    drag = null;
    dirty();
  }

  function onKey(event) {
    if (event.code !== 'Delete' && event.code !== 'Backspace') return;
    const target = event.target;
    if (target && (target.tagName === 'INPUT' || target.tagName === 'TEXTAREA')) return;
    if (selectedId !== null && !dom.editorWrap.closest('[hidden]')) {
      removeElement(selectedId);
      event.preventDefault();
    }
  }

  // -------------------------------------------------------------------------
  // Kartenverwaltung
  // -------------------------------------------------------------------------

  function rebuildSelect() {
    dom.mapSelect.replaceChildren();
    maps.forEach((m, index) => {
      const option = document.createElement('option');
      option.value = String(index);
      option.textContent = `${index + 1}. ${m.name}`;
      dom.mapSelect.append(option);
    });
    dom.mapSelect.value = String(current);
    const m = map();
    if (m !== undefined) {
      dom.mapCell.value = String(m.cell);
      dom.mapCols.value = String(m.cols);
      dom.mapRows.value = String(m.rows);
    }
  }

  function switchTo(index) {
    current = Math.max(0, Math.min(maps.length - 1, index));
    selectedId = null;
    rebuildSelect();
    buildProps();
    draw();
  }

  function dirty(redraw = true) {
    host.onDirty();
    if (redraw) draw();
  }

  function bindBar() {
    dom.mapSelect.addEventListener('change', () => switchTo(Number(dom.mapSelect.value)));
    dom.mapNew.addEventListener('click', () => {
      if (maps.length >= MAP_LIMITS.maxMaps) {
        host.status(`Höchstens ${MAP_LIMITS.maxMaps} Karten.`, true);
        return;
      }
      const name = window.prompt('Name des Levels?', `Level ${maps.length + 1}`);
      if (name === null || name.trim() === '') return;
      maps.push(freshMap(name.trim().slice(0, MAP_LIMITS.nameMax)));
      switchTo(maps.length - 1);
      dirty();
    });
    dom.mapRename.addEventListener('click', () => {
      const m = map();
      if (m === undefined) return;
      const name = window.prompt('Neuer Name?', m.name);
      if (name === null || name.trim() === '') return;
      m.name = name.trim().slice(0, MAP_LIMITS.nameMax);
      rebuildSelect();
      dirty(false);
    });
    dom.mapDelete.addEventListener('click', () => {
      if (maps.length <= 1) {
        host.status('Die letzte Karte bleibt – lösch lieber ihre Elemente.', true);
        return;
      }
      if (!window.confirm(`„${map().name}" wirklich löschen?`)) return;
      maps.splice(current, 1);
      switchTo(Math.max(0, current - 1));
      dirty();
    });
    const swap = (delta) => {
      const other = current + delta;
      if (other < 0 || other >= maps.length) return;
      [maps[current], maps[other]] = [maps[other], maps[current]];
      current = other;
      rebuildSelect();
      dirty(false);
    };
    dom.mapUp.addEventListener('click', () => swap(-1));
    dom.mapDown.addEventListener('click', () => swap(1));

    dom.mapCell.addEventListener('change', () => {
      map().cell = Number(dom.mapCell.value);
      dirty();
    });
    const resize = () => {
      const m = map();
      m.cols = Math.max(
        MAP_LIMITS.minCols,
        Math.min(MAP_LIMITS.maxCols, Math.round(Number(dom.mapCols.value) || m.cols)),
      );
      m.rows = Math.max(
        MAP_LIMITS.minRows,
        Math.min(MAP_LIMITS.maxRows, Math.round(Number(dom.mapRows.value) || m.rows)),
      );
      dom.mapCols.value = String(m.cols);
      dom.mapRows.value = String(m.rows);
      // Elemente, die jetzt draussen liegen, zurückholen.
      for (const e of m.elements) {
        e.x = Math.min(e.x, Math.max(0, m.cols - e.w));
        e.y = Math.min(e.y, Math.max(0, m.rows - e.h));
        e.w = Math.min(e.w, m.cols - e.x);
        e.h = Math.min(e.h, m.rows - e.y);
      }
      m.spawn.x = Math.min(m.spawn.x, m.cols - 1);
      m.spawn.y = Math.min(m.spawn.y, m.rows - 1);
      dirty();
    };
    dom.mapCols.addEventListener('change', resize);
    dom.mapRows.addEventListener('change', resize);

    dom.mapTest.addEventListener('click', () => {
      const checked = validateMap(map());
      if (!checked.ok) {
        host.status(`Karte unvollständig: ${checked.error} (${checked.detail ?? ''})`, true);
        return;
      }
      host.beforeTest?.();
      window.open(`../?map=${encodeURIComponent(map().id)}`, '_blank');
    });

    canvas.addEventListener('pointerdown', onDown);
    canvas.addEventListener('pointermove', onMove);
    canvas.addEventListener('pointerup', onUp);
    canvas.addEventListener('pointercancel', onUp);
    canvas.addEventListener('contextmenu', (event) => event.preventDefault());
    window.addEventListener('keydown', onKey);
  }

  return {
    /** Karten hineinreichen (nach dem Laden). */
    setMaps(list) {
      maps = list.length > 0 ? list : [freshMap('Level 1')];
      current = 0;
      buildPalette();
      bindBar();
      switchTo(0);
    },
    getMaps: () => maps,
    /** Vor dem Speichern: jede Karte muss durch die Prüfung. */
    validateAll() {
      for (const m of maps) {
        const checked = validateMap(m);
        if (!checked.ok) {
          return { ok: false, error: checked.error, detail: checked.detail, name: m.name };
        }
      }
      return { ok: true };
    },
    redraw: draw,
  };
}
