import { defineConfig } from 'vitest/config';

export default defineConfig({
  test: {
    globalSetup: ['./test/global-setup.ts'],
    // Argon2id ist absichtlich langsam; Anmeldetests brauchen deshalb Luft.
    testTimeout: 30_000,
    hookTimeout: 60_000,
    // Tests teilen sich eine Datenbank – deshalb nacheinander statt parallel.
    fileParallelism: false,
  },
});
