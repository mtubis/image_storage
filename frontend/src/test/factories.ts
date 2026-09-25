import type { z } from 'zod';
import type { imagePageSchema, imageSchema } from '@/api/schemas';
import { env } from '@/lib/env';

// Payloads in the exact wire format of the API (snake_case, absolute URLs), for schema tests
// and MSW handlers. Typed from the schemas' input, so a contract change breaks them at compile time.
export type ImagePayload = z.input<typeof imageSchema>;

export type ImagePagePayload = z.input<typeof imagePageSchema> & {
  links: Record<'first' | 'last' | 'prev' | 'next', string | null>;
  meta: { path: string; per_page: number; next_cursor: string | null; prev_cursor: string | null };
};

const CROCKFORD_BASE32 = '0123456789abcdefghjkmnpqrstvwxyz';
let sequence = 0;

// A valid, lowercase ULID (as the API returns them) that is unique per call, so lists built
// from the factory have distinct React keys and URLs.
function nextUlid(): string {
  let suffix = '';
  for (let n = sequence++, i = 0; i < 4; i++, n = Math.floor(n / 32)) {
    suffix = CROCKFORD_BASE32.charAt(n % 32) + suffix;
  }

  return `01m3armzpnjqtwvm6rx55m${suffix}`;
}

export function makeImagePayload(overrides: Partial<ImagePayload> = {}): ImagePayload {
  const id = overrides.id ?? nextUlid();

  return {
    id,
    original_name: 'photo.jpg',
    extension: 'jpg',
    mime_type: 'image/jpeg',
    size_bytes: 6061,
    width: 500,
    height: 640,
    thumbnail_url: `${new URL(env.apiUrl).origin}/storage/thumbnails/${id}.webp`,
    download_url: `${env.apiUrl}/images/${id}/download`,
    uploader_name: 'Jane Doe',
    temperature_c: 11.2,
    created_at: '2026-09-24T22:30:18Z',
    ...overrides,
  };
}

export function makeImagePayloads(count: number): ImagePayload[] {
  return Array.from({ length: count }, () => makeImagePayload());
}

export function makeImagePagePayload(
  images: ImagePayload[],
  nextCursor: string | null = null,
): ImagePagePayload {
  return {
    data: images,
    links: { first: null, last: null, prev: null, next: null },
    meta: {
      path: `${env.apiUrl}/images`,
      per_page: 10,
      next_cursor: nextCursor,
      prev_cursor: null,
    },
  };
}
