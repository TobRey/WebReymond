// Drei Eingaben (links, rechts, springen), drei Wege sie zu geben: Finger,
// Taste, Gamepad.
//
// LATENZ IST HIER DAS THEMA. Zwei Entscheidungen dazu:
//
// 1. Gehorcht wird `pointerdown`, nicht `click`. `click` feuert erst beim
//    Loslassen und auf Touchgeräten teils mit spürbarer Verzögerung.
// 2. Ein Sprungdruck wird GEZÄHLT, nicht nur als Zustand gemerkt. Die Logik
//    läuft mit 60 Schritten pro Sekunde, ein Finger kann zwischen zwei
//    Schritten tippen und loslassen. Ohne den Zähler ginge genau dieser Tipp
//    verloren – der Fehler, den man beim Spielen als "reagiert nicht" fühlt.
//
// Die Touch-Knöpfe verfolgen je FINGER (pointerId), nicht je Knopf: wer mit
// dem Daumen von ◀ auf ▶ rutscht, wechselt die Richtung, ohne neu zu drücken
// – und Laufen + Springen gleichzeitig sind zwei Finger, das muss gehen.

/** event.code statt event.key: layoutunabhängig, damit auch AZERTY passt. */
const KEY_JUMP = new Set(['Space', 'ArrowUp', 'KeyW']);
const KEY_LEFT = new Set(['ArrowLeft', 'KeyA']);
const KEY_RIGHT = new Set(['ArrowRight', 'KeyD']);
/** Diese Tasten würden sonst die Seite scrollen. */
const SWALLOW = new Set(['Space', 'ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight']);

export function createInput() {
  /** Wie oft seit dem letzten Logikschritt gesprungen wurde. */
  let pressCount = 0;
  const keys = { left: false, right: false, jump: false };
  const touch = { left: false, right: false, jump: false };
  let padJumpHeld = false;
  let padPauseWasDown = false;
  let padLeft = false;
  let padRight = false;
  let wasJumpDown = false;

  const state = {
    left: false,
    right: false,
    jump: false,
    jumpPressed: false,
    jumpReleased: false,
  };
  const commands = [];

  function onKeyDown(event) {
    if (event.repeat) return;
    const code = event.code;
    const target = event.target;
    const typing = target && (target.tagName === 'INPUT' || target.tagName === 'TEXTAREA');
    if (typing) return;

    if (KEY_JUMP.has(code)) {
      keys.jump = true;
      pressCount += 1;
    } else if (KEY_LEFT.has(code)) keys.left = true;
    else if (KEY_RIGHT.has(code)) keys.right = true;
    else if (code === 'Escape' || code === 'KeyP') commands.push('pause');
    else if (code === 'KeyR') commands.push('restart');
    else if (code === 'KeyM') commands.push('mute');
    else if (code === 'KeyF') commands.push('fullscreen');
    else return;

    if (SWALLOW.has(code)) event.preventDefault();
  }

  function onKeyUp(event) {
    const code = event.code;
    if (KEY_JUMP.has(code)) keys.jump = false;
    else if (KEY_LEFT.has(code)) keys.left = false;
    else if (KEY_RIGHT.has(code)) keys.right = false;
  }

  function onBlur() {
    keys.left = keys.right = keys.jump = false;
    touch.left = touch.right = touch.jump = false;
    fingers.clear();
  }

  window.addEventListener('keydown', onKeyDown);
  window.addEventListener('keyup', onKeyUp);
  window.addEventListener('blur', onBlur);

  // --- Touch-Knöpfe --------------------------------------------------------
  /** pointerId → 'left'|'right'|'jump', der Knopf, den der Finger gerade hält. */
  const fingers = new Map();
  const bound = [];

  function recountTouch() {
    touch.left = touch.right = touch.jump = false;
    for (const action of fingers.values()) touch[action] = true;
  }

  /**
   * Macht ein DOM-Element zu einem gehaltenen Knopf für eine der drei
   * Eingaben. Mehrere Elemente je Eingabe sind erlaubt.
   */
  function bindHoldButton(element, action) {
    if (element === null) return;
    bound.push([element, action]);

    element.addEventListener(
      'pointerdown',
      (event) => {
        event.preventDefault();
        fingers.set(event.pointerId, action);
        if (action === 'jump') pressCount += 1;
        recountTouch();
        element.classList.add('pad__btn--down');
      },
      { passive: false },
    );

    // Rutscht ein Finger auf einen ANDEREN Knopf, übernimmt der ihn.
    element.addEventListener('pointerenter', (event) => {
      if (!fingers.has(event.pointerId)) return;
      if (fingers.get(event.pointerId) === action) return;
      fingers.set(event.pointerId, action);
      if (action === 'jump') pressCount += 1;
      recountTouch();
    });

    element.addEventListener('contextmenu', (event) => event.preventDefault());
  }

  function releaseFinger(event) {
    if (!fingers.has(event.pointerId)) return;
    fingers.delete(event.pointerId);
    recountTouch();
    for (const [element] of bound) element.classList.remove('pad__btn--down');
    // Die noch gehaltenen wieder markieren.
    for (const action of fingers.values()) {
      for (const [element, a] of bound) {
        if (a === action) element.classList.add('pad__btn--down');
      }
    }
  }
  window.addEventListener('pointerup', releaseFinger);
  window.addEventListener('pointercancel', releaseFinger);

  // --- Gamepad -------------------------------------------------------------
  function button(gp, index) {
    return gp.buttons.length > index && gp.buttons[index].pressed;
  }

  function pollGamepad() {
    const pads = window.navigator.getGamepads ? window.navigator.getGamepads() : [];
    let jump = false;
    let pause = false;
    padLeft = false;
    padRight = false;
    for (const gp of pads) {
      if (!gp) continue;
      if (button(gp, 0) || button(gp, 1)) jump = true;
      if (button(gp, 9)) pause = true;
      if (button(gp, 14)) padLeft = true;
      if (button(gp, 15)) padRight = true;
      const axis = gp.axes.length > 0 ? gp.axes[0] : 0;
      if (axis < -0.4) padLeft = true;
      if (axis > 0.4) padRight = true;
    }
    if (jump && !padJumpHeld) pressCount += 1;
    padJumpHeld = jump;
    if (pause && !padPauseWasDown) commands.push('pause');
    padPauseWasDown = pause;
  }

  return {
    bindHoldButton,

    /** Einmal je Logikschritt aufrufen. */
    update() {
      pollGamepad();

      state.left = keys.left || touch.left || padLeft;
      state.right = keys.right || touch.right || padRight;
      const jumpDown = keys.jump || touch.jump || padJumpHeld;
      // Ein zwischenzeitlicher Tipp zählt auch dann, wenn der Finger beim
      // Abfragen längst wieder weg ist.
      state.jumpPressed = pressCount > 0 || (jumpDown && !wasJumpDown);
      state.jumpReleased = !jumpDown && wasJumpDown;
      state.jump = jumpDown || pressCount > 0;
      pressCount = 0;
      wasJumpDown = jumpDown;
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

    /** Alles loslassen – beim Pausieren und beim Szenenwechsel. */
    releaseAll() {
      onBlur();
      pressCount = 0;
      wasJumpDown = false;
    },

    dispose() {
      window.removeEventListener('keydown', onKeyDown);
      window.removeEventListener('keyup', onKeyUp);
      window.removeEventListener('blur', onBlur);
      window.removeEventListener('pointerup', releaseFinger);
      window.removeEventListener('pointercancel', releaseFinger);
    },
  };
}
