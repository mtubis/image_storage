import '@testing-library/jest-dom/vitest';
import { cleanup } from '@testing-library/react';
import { afterAll, afterEach, beforeAll } from 'vitest';
import { server } from './msw/server';

// MSW turns every intercepted XHR into a Node Request. Vitest's shim that converts jsdom's
// FormData/Blob for it reads a jsdom internal that jsdom 30 no longer has, so a multipart body
// fails with "Cannot read properties of undefined (reading '_buffer')". The tests use Node's
// own classes instead, which the Request reads natively. Data properties, not assignments:
// Vitest's accessors would write through to the jsdom window and trigger the shim again.
// The app's types don't include Node's modules, hence the untyped import; FormData comes from
// Response, which is still Node's (jsdom has none).
const nodeBuffer = 'node:buffer';
const { Blob: NodeBlob, File: NodeFile } = (await import(/* @vite-ignore */ nodeBuffer)) as {
  Blob: typeof Blob;
  File: typeof File;
};
const NodeFormData = (await new Response(new URLSearchParams()).formData()).constructor;
for (const [name, value] of Object.entries({
  Blob: NodeBlob,
  File: NodeFile,
  FormData: NodeFormData,
})) {
  Object.defineProperty(globalThis, name, { value, writable: true, configurable: true });
}

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
