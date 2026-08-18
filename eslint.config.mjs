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
);
