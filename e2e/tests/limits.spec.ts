import { expect, test } from '@playwright/test';
import { API_URL } from './support/api';
import { readFixture } from './support/fixtures';

// No browser: the form stops files over 5 MB before they are sent, so these limits are only
// reachable through the API. They exercise the web server's configuration, which the backend's
// tests can't see.
const FRONTEND_ORIGIN = new URL(process.env.E2E_BASE_URL ?? 'http://localhost:5174').origin;

// valid.jpg padded after its EOI marker: still a decodable JPEG, just larger.
async function jpegOfSize(bytes: number): Promise<Buffer> {
  const jpeg = await readFixture('valid.jpg');

  return Buffer.concat([jpeg, Buffer.alloc(bytes - jpeg.length)]);
}

function upload(buffer: Buffer) {
  return {
    headers: { Accept: 'application/json', Origin: FRONTEND_ORIGIN },
    multipart: {
      file: { name: 'large.jpg', mimeType: 'image/jpeg', buffer },
      uploader_name: 'E2E Test',
      uploader_email: 'e2e-test@example.com',
    },
  };
}

test('a file over 5 MB reaches the app and gets a validation error', async ({ request }) => {
  const response = await request.post(`${API_URL}/images`, upload(await jpegOfSize(6 * 1024 ** 2)));

  expect(response.status()).toBe(422);
  const body = (await response.json()) as { errors: Record<string, string[]> };
  expect(Object.keys(body.errors)).toEqual(['file']);
});

// nginx answers while the body is still being sent; its lingering close (on by default) reads
// the rest, so the client sees the response instead of a reset connection.
test('a body over the web server limit gets a JSON 413 the SPA can read', async ({ request }) => {
  const response = await request.post(
    `${API_URL}/images`,
    upload(await jpegOfSize(11 * 1024 ** 2)),
  );

  expect(response.status()).toBe(413);
  expect(response.headers()['access-control-allow-origin']).toBe(FRONTEND_ORIGIN);
  const body = (await response.json()) as Record<string, unknown>;
  expect(Object.keys(body)).toEqual(['message']);
  expect(typeof body.message).toBe('string');
});
