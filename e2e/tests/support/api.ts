import { expect, type APIRequestContext } from '@playwright/test';
import { readFixture } from './fixtures';

export const API_URL = process.env.E2E_API_URL ?? 'http://localhost:8001/api/v1';

export interface StoredImage {
  id: string;
  original_name: string;
  thumbnail_url: string;
}

// Stores an image directly through the API, for scenarios that are about something else than the
// upload form.
export async function uploadImage(
  request: APIRequestContext,
  { fixture, name }: { fixture: string; name: string },
): Promise<StoredImage> {
  const response = await request.post(`${API_URL}/images`, {
    multipart: {
      // Deliberately generic: the server detects the type from the content, never from the client.
      file: { name, mimeType: 'application/octet-stream', buffer: await readFixture(fixture) },
      uploader_name: 'E2E Test',
      uploader_email: 'e2e-test@example.com',
    },
  });
  expect(response.status()).toBe(201);

  const body = (await response.json()) as { data: StoredImage };

  return body.data;
}

// Cleanup after a test, so repeated runs leave the seeded list as it was. A 404 is fine: the test
// may have deleted the image itself.
export async function deleteImage(request: APIRequestContext, id: string): Promise<void> {
  const response = await request.delete(`${API_URL}/images/${id}`);
  expect([204, 404]).toContain(response.status());
}
