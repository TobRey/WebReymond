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
  {
    // games/mogli ist reines Browser-JavaScript ohne Build-Schritt und
    // damit ohne Bundler, der die Umgebung kennt. Das Paket `globals` ist im
    // Monorepo nicht vorhanden, deshalb stehen die benötigten Namen hier
    // direkt. Der Ordner wird bewusst NICHT ignoriert – geprüft werden soll er.
    files: ['games/**/web/**/*.js'],
    languageOptions: {
      ecmaVersion: 2022,
      sourceType: 'module',
      globals: {
        window: 'readonly',
        document: 'readonly',
        console: 'readonly',
        AbortController: 'readonly',
        Node: 'readonly',
        Image: 'readonly',
        createImageBitmap: 'readonly',
        DataTransfer: 'readonly',
        FileReader: 'readonly',
        fetch: 'readonly',
        FormData: 'readonly',
        Blob: 'readonly',
        URL: 'readonly',
        URLSearchParams: 'readonly',
        sessionStorage: 'readonly',
        localStorage: 'readonly',
        setTimeout: 'readonly',
        clearTimeout: 'readonly',
      },
    },
    rules: {
      // js.configs.recommended schaltet die KERNregel no-unused-vars ein. Die
      // kennt das `_`-Präfix nicht und meldet zusätzlich zur bereits
      // konfigurierten TypeScript-Variante – also doppelt. Hier abschalten,
      // zuständig bleibt @typescript-eslint/no-unused-vars.
      'no-unused-vars': 'off',
    },
  },
  {
    // Packskript und Spieltests laufen in Node.
    files: ['games/**/*.mjs'],
    languageOptions: {
      ecmaVersion: 2022,
      sourceType: 'module',
      globals: {
        console: 'readonly',
        process: 'readonly',
        Buffer: 'readonly',
        // Node 22 bringt diese von sich aus mit; die Gegenprobe gegen
        // Chromium spricht damit CDP, ohne eine Abhängigkeit zu brauchen.
        fetch: 'readonly',
        WebSocket: 'readonly',
        TextEncoder: 'readonly',
        setTimeout: 'readonly',
      },
    },
    rules: {
      'no-unused-vars': 'off',
    },
  },
);
