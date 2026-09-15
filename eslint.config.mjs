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
        confirm: 'readonly',
        console: 'readonly',
        document: 'readonly',
        indexedDB: 'readonly',
        localStorage: 'readonly',
        navigator: 'readonly',
        performance: 'readonly',
        requestAnimationFrame: 'readonly',
        setTimeout: 'readonly',
        Blob: 'readonly',
        URL: 'readonly',
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
