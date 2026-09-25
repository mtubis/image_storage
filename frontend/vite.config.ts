import { fileURLToPath } from 'node:url';
import react from '@vitejs/plugin-react';
import { defineConfig } from 'vitest/config';

export default defineConfig({
  plugins: [react()],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  server: {
    port: 5173,
    // Fail loudly instead of silently moving to another port that
    // Docker doesn't publish.
    strictPort: true,
  },
  test: {
    environment: 'jsdom',
    setupFiles: ['./src/test/setup.ts'],
    // Fixed, so tests never depend on the developer's .env; MSW handlers use the same URL.
    env: {
      VITE_API_URL: 'http://api.test/api/v1',
    },
    restoreMocks: true,
    unstubEnvs: true,
    unstubGlobals: true,
  },
});
