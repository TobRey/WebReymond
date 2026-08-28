// Tastatur, Touch und Gamepad laufen auf einen einzigen Zustand zusammen.
//
// jumpPressed/jumpReleased sind Flankenmerker und werden am Ende jedes
// Logikschritts gelöscht. Nur so funktionieren Sprungpuffer und
// Sprungabbruch zuverlässig, auch wenn der Browser zwei keydown-Ereignisse
// innerhalb eines Ticks liefert.

/** event.code statt event.key: layoutunabhängig, damit auch AZERTY passt. */
const KEY_LEFT = new Set(['ArrowLeft', 'KeyA']);
const KEY_RIGHT = new Set(['ArrowRight', 'KeyD']);
const KEY_DOWN = new Set(['ArrowDown', 'KeyS']);
const KEY_JUMP = new Set(['Space', 'ArrowUp', 'KeyW', 'KeyZ']);
/** Diese Tasten würden sonst die Seite scrollen. */
const SWALLOW = new Set(['Space', 'ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight']);

export function createInput() {
  const held = { left: false, right: false, down: false, jump: false };
  const touch = { left: false, right: false, down: false, jump: false };
  const pad = { left: false, right: false, down: false, jump: false, pause: false };

  const state = {
    left: false,
    right: false,
    down: false,
    jump: false,
    jumpPressed: false,
    jumpReleased: false,
  };

  let jumpWasDown = false;
  let padPauseWasDown = false;
  /** Von der Anwendung abgeholte einmalige Befehle. */
  const commands = [];
  const padPrev = new Map();

  function onKeyDown(event) {
    if (event.repeat) return;
    const code = event.code;
    if (KEY_LEFT.has(code)) held.left = true;
    else if (KEY_RIGHT.has(code)) held.right = true;
    else if (KEY_DOWN.has(code)) held.down = true;
    else if (KEY_JUMP.has(code)) held.jump = true;
    else if (code === 'Escape' || code === 'KeyP') commands.push('pause');
    else if (code === 'KeyR') commands.push('restart');
    else if (code === 'KeyM') commands.push('mute');
    else if (code === 'Enter') commands.push('confirm');
    else return;

    // Nur schlucken, wenn gerade nicht in ein Eingabefeld getippt wird.
    const target = event.target;
    const typing = target && (target.tagName === 'INPUT' || target.tagName === 'TEXTAREA');
    if (!typing && SWALLOW.has(code)) event.preventDefault();
  }

  function onKeyUp(event) {
    const code = event.code;
    if (KEY_LEFT.has(code)) held.left = false;
    else if (KEY_RIGHT.has(code)) held.right = false;
    else if (KEY_DOWN.has(code)) held.down = false;
    else if (KEY_JUMP.has(code)) held.jump = false;
  }

  function onBlur() {
    held.left = false;
    held.right = false;
    held.down = false;
    held.jump = false;
  }

  window.addEventListener('keydown', onKeyDown);
  window.addEventListener('keyup', onKeyUp);
  window.addEventListener('blur', onBlur);

  /** Verdrahtet einen Bildschirmknopf. */
  function bindTouchButton(element, action) {
    if (element === null) return;
    const press = (event) => {
      event.preventDefault();
      touch[action] = true;
      if (event.pointerId !== undefined && element.setPointerCapture) {
        try {
          element.setPointerCapture(event.pointerId);
        } catch {
          // Ein fehlgeschlagenes Capture ist harmlos – der Knopf funktioniert
          // auch ohne, nur das Wegrutschen des Fingers wird nicht verfolgt.
        }
      }
    };
    const release = (event) => {
      event.preventDefault();
      touch[action] = false;
    };
    element.addEventListener('pointerdown', press);
    element.addEventListener('pointerup', release);
    element.addEventListener('pointercancel', release);
    element.addEventListener('pointerleave', release);
  }

  function pollGamepad() {
    const pads = window.navigator.getGamepads ? window.navigator.getGamepads() : [];
    pad.left = false;
    pad.right = false;
    pad.down = false;
    pad.jump = false;
    pad.pause = false;
    for (const gp of pads) {
      if (!gp) continue;
      const axisX = gp.axes.length > 0 ? gp.axes[0] : 0;
      const axisY = gp.axes.length > 1 ? gp.axes[1] : 0;
      if (axisX < -0.35 || button(gp, 14)) pad.left = true;
      if (axisX > 0.35 || button(gp, 15)) pad.right = true;
      if (axisY > 0.5 || button(gp, 13)) pad.down = true;
      if (button(gp, 0) || button(gp, 1)) pad.jump = true;
      if (button(gp, 9)) pad.pause = true;
      padPrev.set(gp.index, true);
    }
  }

  function button(gp, index) {
    return gp.buttons.length > index && gp.buttons[index].pressed;
  }

  return {
    bindTouchButton,

    /** Einmal je Logikschritt aufrufen. */
    update() {
      pollGamepad();

      state.left = held.left || touch.left || pad.left;
      state.right = held.right || touch.right || pad.right;
      state.down = held.down || touch.down || pad.down;
      state.jump = held.jump || touch.jump || pad.jump;

      state.jumpPressed = state.jump && !jumpWasDown;
      state.jumpReleased = !state.jump && jumpWasDown;
      jumpWasDown = state.jump;

      if (pad.pause && !padPauseWasDown) commands.push('pause');
      padPauseWasDown = pad.pause;

      return state;
    },

    get state() {
      return state;
    },

    /** Holt die aufgelaufenen Einmalbefehle ab und leert die Liste. */
    takeCommands() {
      if (commands.length === 0) return [];
      return commands.splice(0, commands.length);
    },

    /** Alle Tasten loslassen – beim Pausieren und beim Szenenwechsel. */
    releaseAll() {
      onBlur();
      touch.left = false;
      touch.right = false;
      touch.down = false;
      touch.jump = false;
      jumpWasDown = false;
    },

    dispose() {
      window.removeEventListener('keydown', onKeyDown);
      window.removeEventListener('keyup', onKeyUp);
      window.removeEventListener('blur', onBlur);
    },
  };
}
