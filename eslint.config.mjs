import js from '@eslint/js';
import tseslint from 'typescript-eslint';

/**
 * Eine Lint-Konfiguration für das gesamte Monorepo.
 * Aufruf: pnpm lint  (im Projektwurzelverzeichnis)
 */
export default tseslint.config(
  {
    ignores: [
      '**/dist/**',
      '**/.next/**',
      '**/coverage/**',
      '**/node_modules/**',
      '**/src/generated/**',
      '**/*.config.js',
      '**/*.config.mjs',
      // Fremdcode und Modellgewichte des Kamera-HUDs (siehe apps/vision-hud).
      'apps/vision-hud/site/assets/vendor/**',
      'apps/vision-hud/site/assets/models/**',
    ],
  },
  js.configs.recommended,
  ...tseslint.configs.recommended,
  {
    rules: {
      // Ungenutzte Variablen sind ein Fehler – ausser sie beginnen mit _.
      '@typescript-eslint/no-unused-vars': [
        'error',
        { argsIgnorePattern: '^_', varsIgnorePattern: '^_' },
      ],
      // Sicherheit: kein stillschweigendes any, keine Shell-nahen Abkürzungen.
      '@typescript-eslint/no-explicit-any': 'error',
      'no-eval': 'error',
      'no-implied-eval': 'error',
      'no-new-func': 'error',
    },
  },
  {
    // In Tests darf gemockt und bewusst falsch typisiert werden.
    files: ['**/test/**/*.ts', '**/test/**/*.tsx', '**/*.test.ts', '**/*.test.tsx'],
    rules: {
      '@typescript-eslint/no-explicit-any': 'off',
    },
  },
  {
    // Das Kamera-HUD ist reines Browser-JavaScript ohne Bundler: keine
    // TypeScript-Regeln, dafür die Browser-Globalen bekannt machen.
    files: ['apps/vision-hud/site/assets/js/**/*.js'],
    languageOptions: {
      sourceType: 'module',
      globals: {
        AbortController: 'readonly',
        Blob: 'readonly',
        SpeechSynthesisUtterance: 'readonly',
        TextDecoder: 'readonly',
        TextEncoder: 'readonly',
        URL: 'readonly',
        atob: 'readonly',
        btoa: 'readonly',
        caches: 'readonly',
        clearInterval: 'readonly',
        clearTimeout: 'readonly',
        confirm: 'readonly',
        console: 'readonly',
        crypto: 'readonly',
        document: 'readonly',
        fetch: 'readonly',
        indexedDB: 'readonly',
        localStorage: 'readonly',
        navigator: 'readonly',
        performance: 'readonly',
        requestAnimationFrame: 'readonly',
        setInterval: 'readonly',
        setTimeout: 'readonly',
      },
    },
  },
  {
    // Der Service Worker läuft in einem eigenen Gültigkeitsbereich – dort gibt
    // es kein `window`, dafür `self`, `caches` und `clients`.
    files: ['apps/vision-hud/site/sw.js'],
    languageOptions: {
      sourceType: 'script',
      globals: {
        URL: 'readonly',
        caches: 'readonly',
        fetch: 'readonly',
        self: 'readonly',
      },
    },
  },
  {
    // Hilfsskripte des Kamera-HUDs laufen in Node.
    files: ['apps/vision-hud/tools/**/*.mjs'],
    languageOptions: {
      sourceType: 'module',
      globals: {
        Buffer: 'readonly',
        URL: 'readonly',
        console: 'readonly',
        fetch: 'readonly',
        process: 'readonly',
      },
    },
  },
);
