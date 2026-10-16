import { defineConfig } from 'vite';
import preact from '@preact/preset-vite';

// Build-Ausgabe landet in ../assets (wird mit ausgeliefert). Relative Basis, damit
// die Anwendung in Domain-Root und Unterordnern gleichermassen funktioniert.
export default defineConfig({
  plugins: [preact()],
  base: './',
  build: {
    outDir: '../assets',
    emptyOutDir: false,
    manifest: 'manifest.json',
    sourcemap: false,
    target: 'es2020',
    rollupOptions: {
      input: 'src/main.tsx',
      output: {
        entryFileNames: 'app-[hash].js',
        chunkFileNames: 'chunk-[hash].js',
        assetFileNames: '[name]-[hash][extname]',
      },
    },
  },
});
