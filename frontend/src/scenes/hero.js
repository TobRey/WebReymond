/**
 * Die 3D-Szene im Hero.
 * ======================
 *
 * Aufbau nach dem Vorbild des Logos:
 *   - das "W" als räumlicher Körper aus vier Strichen
 *   - der türkise Haken, der im Logo im W steckt
 *   - der Drahtgitter-Globus dahinter, mit leuchtenden Knotenpunkten
 *   - ein Partikelfeld für Tiefe
 *   - ein fliessender Verlauf als Hintergrund (eigener Shader)
 *
 * Diese Datei wird absichtlich erst NACH dem ersten Bild geladen und nur,
 * wenn das Gerät sie verkraftet (siehe scripts/env.js). Wer sie nicht
 * bekommt, sieht den CSS-Verlauf – der ist bewusst schön und nicht leer.
 */

import * as THREE from 'three';
import { clamp, lerp } from '../scripts/env.js';

/* ------------------------------------------------------------------ */
/* Farben – dieselben Werte wie in tokens.css                          */
/* ------------------------------------------------------------------ */

const PALETTE = {
  dark: {
    indigo: 0x4b33c8,
    indigoLight: 0x8b85ff,
    teal: 0x17c8c8,
    tealLight: 0x2fe0dc,
    background: 0x06060f,
    fog: 0x0a0a1f,
  },
  light: {
    indigo: 0x2b1b9e,
    indigoLight: 0x6c5ce7,
    teal: 0x12a9a9,
    tealLight: 0x17c8c8,
    background: 0xf6f7fb,
    fog: 0xffffff,
  },
};

/* ------------------------------------------------------------------ */
/* Bausteine                                                           */
/* ------------------------------------------------------------------ */

/**
 * Ein Strich des Buchstabens: ein Viereck mit senkrechten Enden,
 * genau wie bei einer geometrischen Schrift.
 */
function strokeShape(from, to, halfWidth) {
  const shape = new THREE.Shape();
  shape.moveTo(from.x - halfWidth, from.y);
  shape.lineTo(from.x + halfWidth, from.y);
  shape.lineTo(to.x + halfWidth, to.y);
  shape.lineTo(to.x - halfWidth, to.y);
  shape.closePath();
  return shape;
}

/**
 * Das "W" aus dem Logo.
 * Die Punkte beschreiben den Verlauf: oben links, Tal, Spitze, Tal, oben rechts.
 */
function buildW(material) {
  const points = [
    new THREE.Vector2(-0.92, 0.62),
    new THREE.Vector2(-0.45, -0.62),
    new THREE.Vector2(0.0, 0.30),
    new THREE.Vector2(0.45, -0.62),
    new THREE.Vector2(0.92, 0.62),
  ];

  const extrude = {
    depth: 0.26,
    bevelEnabled: true,
    bevelThickness: 0.035,
    bevelSize: 0.028,
    bevelSegments: 3,
    curveSegments: 2,
  };

  const group = new THREE.Group();

  for (let i = 0; i < points.length - 1; i++) {
    const shape = strokeShape(points[i], points[i + 1], 0.165);
    const geometry = new THREE.ExtrudeGeometry(shape, extrude);
    geometry.center();

    const mesh = new THREE.Mesh(geometry, material);
    // center() hat jeden Strich in den Ursprung geschoben – zurück an seinen Platz.
    const midX = (points[i].x + points[i + 1].x) / 2;
    const midY = (points[i].y + points[i + 1].y) / 2;
    mesh.position.set(midX, midY, 0);
    group.add(mesh);
  }

  return group;
}

/** Der türkise Haken, der im Logo im W sitzt. */
function buildCheck(material) {
  const shape = new THREE.Shape();
  // Kurzer Schenkel nach unten rechts, langer Schenkel nach oben rechts.
  shape.moveTo(-0.30, 0.06);
  shape.lineTo(-0.11, -0.16);
  shape.lineTo(0.34, 0.44);
  shape.lineTo(0.20, 0.56);
  shape.lineTo(-0.12, 0.13);
  shape.lineTo(-0.20, 0.22);
  shape.closePath();

  const geometry = new THREE.ExtrudeGeometry(shape, {
    depth: 0.14,
    bevelEnabled: true,
    bevelThickness: 0.02,
    bevelSize: 0.018,
    bevelSegments: 2,
    curveSegments: 2,
  });
  geometry.center();

  return new THREE.Mesh(geometry, material);
}

/**
 * Der Globus: Längen- und Breitenkreise als Linien, dazu leuchtende
 * Knotenpunkte an einigen Kreuzungen – wie im Logo.
 */
function buildGlobe(colors) {
  const group = new THREE.Group();
  const radius = 1.0;

  const lineMaterial = new THREE.LineBasicMaterial({
    color: colors.teal,
    transparent: true,
    opacity: 0.42,
  });

  // Längenkreise
  for (let i = 0; i < 6; i++) {
    const curve = new THREE.EllipseCurve(0, 0, radius, radius, 0, Math.PI * 2, false, 0);
    const geometry = new THREE.BufferGeometry().setFromPoints(curve.getPoints(72));
    const line = new THREE.LineLoop(geometry, lineMaterial);
    line.rotation.y = (i / 6) * Math.PI;
    group.add(line);
  }

  // Breitenkreise
  for (let i = 1; i < 5; i++) {
    const t = i / 5;
    const y = Math.cos(t * Math.PI) * radius;
    const r = Math.sin(t * Math.PI) * radius;
    const curve = new THREE.EllipseCurve(0, 0, r, r, 0, Math.PI * 2, false, 0);
    const geometry = new THREE.BufferGeometry().setFromPoints(curve.getPoints(64));
    const line = new THREE.LineLoop(geometry, lineMaterial);
    line.rotation.x = Math.PI / 2;
    line.position.y = y;
    group.add(line);
  }

  // Knotenpunkte
  const nodeGeometry = new THREE.SphereGeometry(0.042, 12, 12);
  const nodeMaterial = new THREE.MeshBasicMaterial({ color: colors.tealLight });
  const nodePositions = [
    [0.62, 0.42, 0.66], [-0.48, 0.70, 0.53], [0.30, -0.30, 0.90],
    [-0.72, -0.35, 0.60], [0.88, -0.12, 0.46], [-0.15, 0.92, -0.36],
  ];

  const nodes = new THREE.Group();
  for (const [x, y, z] of nodePositions) {
    const node = new THREE.Mesh(nodeGeometry, nodeMaterial);
    node.position.set(x, y, z).normalize().multiplyScalar(radius);
    nodes.add(node);
  }
  group.add(nodes);
  group.userData.nodes = nodes;

  return group;
}

/** Ein Feld aus Punkten, das der Szene Tiefe gibt. */
function buildParticles(count, colors) {
  const positions = new Float32Array(count * 3);
  const scatter = new Float32Array(count * 3);
  const sizes = new Float32Array(count);

  for (let i = 0; i < count; i++) {
    // Gleichmässig in einer Kugelschale verteilen
    const radius = 3.4 + Math.random() * 7.5;
    const theta = Math.random() * Math.PI * 2;
    const phi = Math.acos(2 * Math.random() - 1);

    positions[i * 3] = radius * Math.sin(phi) * Math.cos(theta);
    positions[i * 3 + 1] = radius * Math.sin(phi) * Math.sin(theta) * 0.6;
    positions[i * 3 + 2] = radius * Math.cos(phi);

    // Richtung, in die der Punkt beim Scrollen davonfliegt
    scatter[i * 3] = (Math.random() - 0.5) * 2;
    scatter[i * 3 + 1] = (Math.random() - 0.5) * 2;
    scatter[i * 3 + 2] = (Math.random() - 0.5) * 2;

    sizes[i] = Math.random() * 0.028 + 0.008;
  }

  const geometry = new THREE.BufferGeometry();
  geometry.setAttribute('position', new THREE.BufferAttribute(positions, 3));
  geometry.setAttribute('aScatter', new THREE.BufferAttribute(scatter, 3));
  geometry.setAttribute('aSize', new THREE.BufferAttribute(sizes, 1));

  const material = new THREE.ShaderMaterial({
    transparent: true,
    depthWrite: false,
    blending: THREE.AdditiveBlending,
    uniforms: {
      uTime: { value: 0 },
      uDisperse: { value: 0 },
      uColorA: { value: new THREE.Color(colors.indigoLight) },
      uColorB: { value: new THREE.Color(colors.tealLight) },
      uPixelRatio: { value: 1 },
    },
    vertexShader: `
      attribute vec3 aScatter;
      attribute float aSize;
      uniform float uTime;
      uniform float uDisperse;
      uniform float uPixelRatio;
      varying float vMix;
      varying float vFade;

      void main() {
        vec3 pos = position;

        // Ruhiges Treiben
        pos.x += sin(uTime * 0.22 + position.z * 0.5) * 0.16;
        pos.y += cos(uTime * 0.18 + position.x * 0.5) * 0.16;

        // Beim Scrollen auseinanderstieben
        pos += aScatter * uDisperse * 5.5;

        vec4 mvPosition = modelViewMatrix * vec4(pos, 1.0);
        gl_Position = projectionMatrix * mvPosition;

        // Weiter entfernt heisst kleiner
        gl_PointSize = aSize * 620.0 * uPixelRatio / max(-mvPosition.z, 0.001);

        vMix = clamp((position.x + 6.0) / 12.0, 0.0, 1.0);
        vFade = 1.0 - uDisperse * 0.85;
      }
    `,
    fragmentShader: `
      uniform vec3 uColorA;
      uniform vec3 uColorB;
      varying float vMix;
      varying float vFade;

      void main() {
        // Runder, weich auslaufender Punkt
        float d = length(gl_PointCoord - vec2(0.5));
        if (d > 0.5) discard;
        float alpha = smoothstep(0.5, 0.06, d) * vFade;

        gl_FragColor = vec4(mix(uColorA, uColorB, vMix), alpha * 0.85);
      }
    `,
  });

  return new THREE.Points(geometry, material);
}

/**
 * Der Hintergrund: ein fliessender Verlauf, der nie stillsteht.
 * Läuft komplett auf der Grafikkarte und kostet praktisch nichts.
 */
function buildBackdrop(colors) {
  const geometry = new THREE.PlaneGeometry(2, 2);

  const material = new THREE.ShaderMaterial({
    depthWrite: false,
    depthTest: false,
    uniforms: {
      uTime: { value: 0 },
      uPointer: { value: new THREE.Vector2(0.5, 0.5) },
      uAspect: { value: 1 },
      uColorA: { value: new THREE.Color(colors.indigo) },
      uColorB: { value: new THREE.Color(colors.teal) },
      uColorBg: { value: new THREE.Color(colors.background) },
      uIntensity: { value: 1 },
    },
    vertexShader: `
      varying vec2 vUv;
      void main() {
        vUv = uv;
        gl_Position = vec4(position.xy, 0.0, 1.0);
      }
    `,
    fragmentShader: `
      precision highp float;

      varying vec2 vUv;
      uniform float uTime;
      uniform vec2 uPointer;
      uniform float uAspect;
      uniform vec3 uColorA;
      uniform vec3 uColorB;
      uniform vec3 uColorBg;
      uniform float uIntensity;

      // Einfaches Rauschen – die Grundlage für weiche, unregelmässige Flecken
      vec2 hash(vec2 p) {
        p = vec2(dot(p, vec2(127.1, 311.7)), dot(p, vec2(269.5, 183.3)));
        return -1.0 + 2.0 * fract(sin(p) * 43758.5453123);
      }

      float noise(vec2 p) {
        vec2 i = floor(p);
        vec2 f = fract(p);
        vec2 u = f * f * (3.0 - 2.0 * f);
        return mix(
          mix(dot(hash(i + vec2(0.0, 0.0)), f - vec2(0.0, 0.0)),
              dot(hash(i + vec2(1.0, 0.0)), f - vec2(1.0, 0.0)), u.x),
          mix(dot(hash(i + vec2(0.0, 1.0)), f - vec2(0.0, 1.0)),
              dot(hash(i + vec2(1.0, 1.0)), f - vec2(1.0, 1.0)), u.x),
          u.y
        );
      }

      float fbm(vec2 p) {
        float value = 0.0;
        float amplitude = 0.5;
        for (int i = 0; i < 4; i++) {
          value += amplitude * noise(p);
          p *= 2.03;
          amplitude *= 0.5;
        }
        return value;
      }

      void main() {
        vec2 uv = vUv;
        vec2 p = (uv - 0.5) * vec2(uAspect, 1.0);

        float t = uTime * 0.045;
        float n = fbm(p * 1.7 + vec2(t, -t * 0.7));
        float n2 = fbm(p * 2.6 - vec2(t * 0.5, t * 0.9) + n);

        // Zwei weiche Lichtquellen, eine folgt der Maus
        vec2 pointer = (uPointer - 0.5) * vec2(uAspect, 1.0);
        float glowA = 1.0 - smoothstep(0.0, 1.15, length(p - pointer * 0.55) - n * 0.28);
        float glowB = 1.0 - smoothstep(0.0, 1.35, length(p + vec2(0.55, 0.30)) - n2 * 0.32);

        vec3 color = uColorBg;
        color = mix(color, uColorA, clamp(glowA * 0.55 * uIntensity, 0.0, 1.0));
        color = mix(color, uColorB, clamp(glowB * 0.38 * uIntensity, 0.0, 1.0));

        // Feine Körnung gegen Streifenbildung im Verlauf
        float grain = fract(sin(dot(uv, vec2(12.9898, 78.233))) * 43758.5453);
        color += (grain - 0.5) * 0.016;

        gl_FragColor = vec4(color, 1.0);
      }
    `,
  });

  const mesh = new THREE.Mesh(geometry, material);
  mesh.frustumCulled = false;
  mesh.renderOrder = -1;
  return mesh;
}

/* ------------------------------------------------------------------ */
/* Die Szene                                                           */
/* ------------------------------------------------------------------ */

export function initHero(container) {
  if (!container) return null;

  const isLight = document.documentElement.getAttribute('data-theme') === 'light';
  let colors = isLight ? PALETTE.light : PALETTE.dark;

  const renderer = new THREE.WebGLRenderer({
    antialias: true,
    alpha: false,
    powerPreference: 'high-performance',
  });

  // Über 1.75 lohnt sich der Aufwand nicht mehr sichtbar, kostet aber viel.
  const pixelRatio = Math.min(window.devicePixelRatio || 1, 1.75);
  renderer.setPixelRatio(pixelRatio);
  renderer.setSize(container.clientWidth, container.clientHeight);
  renderer.setClearColor(colors.background, 1);
  renderer.toneMapping = THREE.ACESFilmicToneMapping;
  renderer.toneMappingExposure = 1.15;
  container.append(renderer.domElement);

  const scene = new THREE.Scene();
  scene.fog = new THREE.Fog(colors.fog, 7, 18);

  const camera = new THREE.PerspectiveCamera(
    42,
    container.clientWidth / container.clientHeight,
    0.1,
    100
  );
  camera.position.set(0, 0, 6.2);

  // -- Hintergrund (eigene Kamera, damit er immer die Fläche füllt) --
  const backdropScene = new THREE.Scene();
  const backdropCamera = new THREE.OrthographicCamera(-1, 1, 1, -1, 0, 1);
  const backdrop = buildBackdrop(colors);
  backdropScene.add(backdrop);

  // -- Licht --
  const ambient = new THREE.AmbientLight(0xffffff, isLight ? 1.1 : 0.55);
  scene.add(ambient);

  const keyLight = new THREE.DirectionalLight(0xffffff, isLight ? 2.0 : 2.6);
  keyLight.position.set(3.2, 4.0, 5.0);
  scene.add(keyLight);

  // Türkiser Rand von rechts – das ist der Marken-Look
  const rimLight = new THREE.PointLight(colors.tealLight, 34, 16, 2);
  rimLight.position.set(3.6, -1.2, 2.4);
  scene.add(rimLight);

  // Indigoblauer Gegenschein von links
  const fillLight = new THREE.PointLight(colors.indigoLight, 26, 16, 2);
  fillLight.position.set(-3.8, 2.0, 1.8);
  scene.add(fillLight);

  // -- Materialien --
  const wMaterial = new THREE.MeshStandardMaterial({
    color: colors.indigo,
    metalness: 0.72,
    roughness: 0.22,
  });

  const checkMaterial = new THREE.MeshStandardMaterial({
    color: colors.teal,
    metalness: 0.55,
    roughness: 0.18,
    emissive: new THREE.Color(colors.teal),
    emissiveIntensity: isLight ? 0.08 : 0.28,
  });

  // -- Aufbau --
  const stage = new THREE.Group();
  scene.add(stage);

  /**
   * Wohin gehört die Szene?
   *
   * Auf breiten Bildschirmen nach rechts, damit sie die Schlagzeile nicht
   * überlagert. Auf schmalen in die Mitte und deutlich kleiner – dort liegt
   * sie hinter dem Text und dient nur noch als Stimmung.
   */
  function layoutStage() {
    const wide = container.clientWidth >= 1000;
    const veryWide = container.clientWidth >= 1400;

    stage.position.x = wide ? (veryWide ? 2.45 : 1.95) : 0;
    stage.scale.setScalar(wide ? 0.82 : 0.62);
    stage.userData.baseX = stage.position.x;
  }

  const wGroup = buildW(wMaterial);
  wGroup.scale.setScalar(1.42);
  wGroup.position.set(-0.55, 0.05, 0.35);
  stage.add(wGroup);

  const check = buildCheck(checkMaterial);
  check.scale.setScalar(1.30);
  check.position.set(-0.34, 0.30, 0.62);
  stage.add(check);

  const globe = buildGlobe(colors);
  globe.scale.setScalar(1.32);
  globe.position.set(0.80, -0.05, -0.7);
  stage.add(globe);

  const particles = buildParticles(1400, colors);
  particles.material.uniforms.uPixelRatio.value = pixelRatio;
  scene.add(particles);

  /* ---------------------------------------------------------------- */
  /* Zustand und Steuerung                                             */
  /* ---------------------------------------------------------------- */

  const pointer = { x: 0.5, y: 0.5, tx: 0.5, ty: 0.5 };
  let scrollProgress = 0;
  let visible = true;
  let running = true;
  let rafId = 0;
  const clock = new THREE.Clock();

  const onPointerMove = (event) => {
    pointer.tx = event.clientX / window.innerWidth;
    pointer.ty = event.clientY / window.innerHeight;
  };

  const onScroll = () => {
    // 0 solange der Hero voll sichtbar ist, 1 wenn er ganz weg ist.
    scrollProgress = clamp(window.scrollY / Math.max(window.innerHeight, 1), 0, 1);
  };

  const onResize = () => {
    const width = container.clientWidth;
    const height = container.clientHeight;
    if (width === 0 || height === 0) return;

    camera.aspect = width / height;
    camera.updateProjectionMatrix();
    renderer.setSize(width, height);
    backdrop.material.uniforms.uAspect.value = width / height;
    layoutStage();
  };

  /**
   * Nur rechnen, solange der Hero im Bild ist.
   *
   * Die Leinwand selbst liegt fest über dem ganzen Fenster und wäre damit
   * immer "sichtbar" – beobachtet wird deshalb der Hero-Abschnitt. Ist er
   * weggescrollt, steht die Szene still und kostet nichts mehr.
   */
  const heroSection = document.querySelector('.wa-hero') ?? container;
  const visibility = new IntersectionObserver(
    ([entry]) => {
      visible = entry.isIntersecting;
    },
    { threshold: 0 }
  );
  visibility.observe(heroSection);

  const onVisibilityChange = () => {
    running = !document.hidden;
    if (running) {
      clock.start();
      loop();
    }
  };

  // Theme-Wechsel: Farben umhängen, ohne die Szene neu zu bauen.
  const onThemeChange = (event) => {
    const light = event.detail?.theme === 'light';
    colors = light ? PALETTE.light : PALETTE.dark;

    renderer.setClearColor(colors.background, 1);
    scene.fog.color.setHex(colors.fog);
    ambient.intensity = light ? 1.1 : 0.55;
    keyLight.intensity = light ? 2.0 : 2.6;
    wMaterial.color.setHex(colors.indigo);
    checkMaterial.color.setHex(colors.teal);
    checkMaterial.emissive.setHex(colors.teal);
    checkMaterial.emissiveIntensity = light ? 0.08 : 0.28;
    rimLight.color.setHex(colors.tealLight);
    fillLight.color.setHex(colors.indigoLight);

    backdrop.material.uniforms.uColorA.value.setHex(colors.indigo);
    backdrop.material.uniforms.uColorB.value.setHex(colors.teal);
    backdrop.material.uniforms.uColorBg.value.setHex(colors.background);
    particles.material.uniforms.uColorA.value.setHex(colors.indigoLight);
    particles.material.uniforms.uColorB.value.setHex(colors.tealLight);

    globe.traverse((child) => {
      if (child.material && child.material.isLineBasicMaterial) {
        child.material.color.setHex(colors.teal);
      }
    });
    globe.userData.nodes?.children.forEach((node) => node.material.color.setHex(colors.tealLight));
  };

  window.addEventListener('pointermove', onPointerMove, { passive: true });
  window.addEventListener('scroll', onScroll, { passive: true });
  window.addEventListener('resize', onResize, { passive: true });
  document.addEventListener('visibilitychange', onVisibilityChange);
  document.addEventListener('wa:theme', onThemeChange);

  onResize();
  onScroll();

  /* ---------------------------------------------------------------- */
  /* Bildschleife                                                      */
  /* ---------------------------------------------------------------- */

  function loop() {
    if (!running) return;
    rafId = requestAnimationFrame(loop);
    if (!visible) return;

    const elapsed = clock.getElapsedTime();

    // Maus weich nachziehen statt hart springen
    pointer.x = lerp(pointer.x, pointer.tx, 0.045);
    pointer.y = lerp(pointer.y, pointer.ty, 0.045);

    const px = (pointer.x - 0.5) * 2;
    const py = (pointer.y - 0.5) * 2;

    // Die ganze Bühne neigt sich zur Maus
    stage.rotation.y = lerp(stage.rotation.y, px * 0.34, 0.06);
    stage.rotation.x = lerp(stage.rotation.x, py * 0.20, 0.06);

    // Beim Scrollen kippt und entfernt sich die Bühne
    stage.position.x = (stage.userData.baseX ?? 0) + scrollProgress * 0.9;
    stage.position.y = scrollProgress * 1.9;
    stage.position.z = scrollProgress * -3.4;
    stage.rotation.z = scrollProgress * 0.32;

    // Das W atmet leicht
    wGroup.rotation.z = Math.sin(elapsed * 0.42) * 0.035;
    wGroup.position.y = 0.05 + Math.sin(elapsed * 0.62) * 0.05;

    // Der Haken schwebt in der eigenen Ebene
    check.rotation.z = Math.sin(elapsed * 0.54 + 1.0) * 0.06;
    check.position.y = 0.30 + Math.sin(elapsed * 0.72 + 0.6) * 0.055;

    // Der Globus dreht sich gleichmässig
    globe.rotation.y = elapsed * 0.16;
    globe.rotation.x = Math.sin(elapsed * 0.22) * 0.12;

    // Die Knoten pulsieren versetzt
    globe.userData.nodes?.children.forEach((node, index) => {
      const pulse = 1 + Math.sin(elapsed * 2.1 + index * 1.3) * 0.28;
      node.scale.setScalar(pulse);
    });

    particles.rotation.y = elapsed * 0.022;
    particles.material.uniforms.uTime.value = elapsed;
    particles.material.uniforms.uDisperse.value = lerp(
      particles.material.uniforms.uDisperse.value,
      scrollProgress,
      0.06
    );

    backdrop.material.uniforms.uTime.value = elapsed;
    backdrop.material.uniforms.uPointer.value.set(pointer.x, 1 - pointer.y);
    backdrop.material.uniforms.uIntensity.value = 1 - scrollProgress * 0.55;

    // Beim Verlassen des Heros verschwindet die Szene. Sonst läge sie über
    // den Texten der folgenden Abschnitte und machte sie schwer lesbar.
    container.style.setProperty(
      '--canvas-opacity',
      clamp(1 - scrollProgress * 1.35, 0, 1).toFixed(3)
    );

    renderer.autoClear = true;
    renderer.render(backdropScene, backdropCamera);
    renderer.autoClear = false;
    renderer.render(scene, camera);
  }

  loop();
  container.classList.add('is-ready');

  /* ---------------------------------------------------------------- */
  /* Aufräumen                                                         */
  /* ---------------------------------------------------------------- */

  return {
    destroy() {
      running = false;
      cancelAnimationFrame(rafId);

      window.removeEventListener('pointermove', onPointerMove);
      window.removeEventListener('scroll', onScroll);
      window.removeEventListener('resize', onResize);
      document.removeEventListener('visibilitychange', onVisibilityChange);
      document.removeEventListener('wa:theme', onThemeChange);
      visibility.disconnect();

      // Grafikspeicher freigeben – sonst bleibt er belegt.
      scene.traverse((object) => {
        if (object.geometry) object.geometry.dispose();
        if (object.material) {
          const materials = Array.isArray(object.material) ? object.material : [object.material];
          materials.forEach((material) => material.dispose());
        }
      });
      backdrop.geometry.dispose();
      backdrop.material.dispose();
      renderer.dispose();
      renderer.domElement.remove();
    },
  };
}
