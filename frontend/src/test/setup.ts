import '@testing-library/jest-dom/vitest';
import { cleanup } from '@testing-library/react';
import { afterAll, afterEach, beforeAll } from 'vitest';
import { server } from './msw/server';

// Any request without a matching handler fails the test, so no test can
// silently hit the real network.
beforeAll(() => {
  server.listen({ onUnhandledRequest: 'error' });
});

afterEach(() => {
  // RTL only auto-cleans up when Vitest globals are enabled; we import explicitly.
  cleanup();
  server.resetHandlers();
});

afterAll(() => {
  server.close();
});
