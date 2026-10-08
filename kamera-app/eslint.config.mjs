const browser = Object.fromEntries('window document navigator location localStorage indexedDB IDBKeyRange fetch AbortController URL Blob File FileReader Image performance requestAnimationFrame setTimeout clearTimeout setInterval console CustomEvent EventTarget IntersectionObserver Intl Node getComputedStyle crypto caches self matchMedia HTMLElement'.split(' ').map((k) => [k, 'readonly']));
const node = Object.fromEntries('process Buffer console fetch setTimeout crypto'.split(' ').map((k) => [k, 'readonly']));
const rules = { 'no-undef': 'error', 'no-unused-vars': ['error', { args: 'none', ignoreRestSiblings: true }], 'no-unreachable': 'error', 'no-dupe-keys': 'error', 'no-redeclare': 'error', 'no-const-assign': 'error', 'no-self-assign': 'error', 'no-unsafe-finally': 'error', 'no-fallthrough': 'error', 'no-empty': ['error', { allowEmptyCatch: true }], 'no-cond-assign': 'error', 'use-isnan': 'error', 'valid-typeof': 'error', 'no-dupe-else-if': 'error' };
export default [
  { files: ['web/**/*.js'], languageOptions: { sourceType: 'module', globals: browser }, rules },
  { files: ['hosting/sw.js'], languageOptions: { sourceType: 'script', globals: browser }, rules },
  { files: ['tests/*.mjs'], languageOptions: { sourceType: 'module', globals: { ...node, window: 'readonly', document: 'readonly', navigator: 'readonly', getComputedStyle: 'readonly' } }, rules },
];
