import { describe, expect, it } from 'vitest';
import { apiErrorSchema, imagePageSchema, imageSchema, validationErrorSchema } from '@/api/schemas';
import { makeImagePagePayload, makeImagePayload } from '@/test/factories';

describe('imageSchema', () => {
  it('parses an image as returned by the API', () => {
    expect(imageSchema.parse(makeImagePayload({ id: '01m3armzpnjqtwvm6rx55mdgw6' }))).toEqual({
      id: '01m3armzpnjqtwvm6rx55mdgw6',
      original_name: 'photo.jpg',
      extension: 'jpg',
      mime_type: 'image/jpeg',
      size_bytes: 6061,
      width: 500,
      height: 640,
      thumbnail_url: 'http://api.test/storage/thumbnails/01m3armzpnjqtwvm6rx55mdgw6.webp',
      download_url: 'http://api.test/api/v1/images/01m3armzpnjqtwvm6rx55mdgw6/download',
      uploader_name: 'Jane Doe',
      temperature_c: 11.2,
      created_at: '2026-09-24T22:30:18Z',
    });
  });

  // What the development stack returns; a domain-only URL check would reject `localhost`.
  it('accepts URLs on localhost with a port', () => {
    const payload = makeImagePayload({
      thumbnail_url: 'http://localhost:8000/storage/thumbnails/01m3armzpnjqtwvm6rx55mdgw6.webp',
      download_url: 'http://localhost:8000/api/v1/images/01m3armzpnjqtwvm6rx55mdgw6/download',
    });

    expect(imageSchema.safeParse(payload).success).toBe(true);
  });

  it('accepts an upload time with a numeric UTC offset', () => {
    const payload = makeImagePayload({ created_at: '2026-09-24T22:30:18+00:00' });

    expect(imageSchema.safeParse(payload).success).toBe(true);
  });

  // The temperature is fetched by a background job after the upload.
  it('accepts a missing temperature', () => {
    expect(imageSchema.parse(makeImagePayload({ temperature_c: null })).temperature_c).toBeNull();
  });

  it('accepts an extension it does not know, since the server decides what is allowed', () => {
    expect(imageSchema.parse(makeImagePayload({ extension: 'heic' })).extension).toBe('heic');
  });

  it('drops fields the UI does not model, so nothing unexpected leaks into components', () => {
    const parsed = imageSchema.parse({ ...makeImagePayload(), uploader_email: 'jane@example.com' });

    expect(parsed).not.toHaveProperty('uploader_email');
  });

  it.each([
    ['id', 'not-a-ulid'],
    ['id', 42],
    ['size_bytes', -1],
    ['size_bytes', 1.5],
    ['size_bytes', '6061'],
    ['width', 0],
    ['height', null],
    ['thumbnail_url', '/storage/thumbnails/a.webp'],
    ['download_url', 'javascript:alert(1)'],
    ['temperature_c', '11.2'],
    ['created_at', '2026-09-24 22:30:18'],
    ['uploader_name', undefined],
  ])('rejects an invalid %s (%j)', (field, value) => {
    expect(imageSchema.safeParse(makeImagePayload({ [field]: value })).success).toBe(false);
  });
});

describe('imagePageSchema', () => {
  it('parses a page with a next cursor', () => {
    const page = imagePageSchema.parse(makeImagePagePayload([makeImagePayload()], 'eyJpZCI6MX0'));

    expect(page.data).toHaveLength(1);
    expect(page.meta.next_cursor).toBe('eyJpZCI6MX0');
  });

  it('parses the last page, which has no next cursor', () => {
    expect(
      imagePageSchema.parse(makeImagePagePayload([makeImagePayload()])).meta.next_cursor,
    ).toBeNull();
  });

  it('parses an empty list', () => {
    expect(imagePageSchema.parse(makeImagePagePayload([])).data).toEqual([]);
  });

  it('rejects a page containing an invalid image', () => {
    const payload = makeImagePagePayload([makeImagePayload(), makeImagePayload({ width: -1 })]);

    expect(imagePageSchema.safeParse(payload).success).toBe(false);
  });

  it('rejects a page without pagination meta', () => {
    expect(imagePageSchema.safeParse({ data: [] }).success).toBe(false);
  });
});

describe('validationErrorSchema', () => {
  it("parses Laravel's 422 response", () => {
    const payload = {
      message: 'The file field is required. (and 1 more error)',
      errors: {
        file: ['The file field is required.'],
        uploader_email: ['The e-mail field must be a valid email address.'],
      },
    };

    expect(validationErrorSchema.parse(payload)).toEqual(payload);
  });

  it.each([
    ['a message only', { message: 'Not found.' }],
    ['messages that are not a list', { message: 'Invalid.', errors: { file: 'Invalid.' } }],
  ])('rejects %s', (_case, payload) => {
    expect(validationErrorSchema.safeParse(payload).success).toBe(false);
  });
});

describe('apiErrorSchema', () => {
  it.each([
    ['a 404', { message: 'Not found.' }],
    ['a 413 from nginx', { message: 'The POST data is too large.' }],
  ])('parses %s', (_case, payload) => {
    expect(apiErrorSchema.parse(payload)).toEqual(payload);
  });

  it('rejects a body without a message, e.g. an HTML error page parsed as text', () => {
    expect(apiErrorSchema.safeParse('<html>Bad Gateway</html>').success).toBe(false);
  });
});
