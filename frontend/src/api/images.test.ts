import { http, HttpResponse } from 'msw';
import { describe, expect, it } from 'vitest';
import { ZodError } from 'zod';
import { fetchImagePage } from '@/api/images';
import { makeImagePagePayload, makeImagePayloads } from '@/test/factories';
import { IMAGES_URL } from '@/test/msw/handlers';
import { server } from '@/test/msw/server';

describe('fetchImagePage', () => {
  it('requests the first page without a cursor', async () => {
    const images = makeImagePayloads(2);
    let search: string | undefined;
    server.use(
      http.get(IMAGES_URL, ({ request }) => {
        search = new URL(request.url).search;

        return HttpResponse.json(makeImagePagePayload(images, 'next-cursor'));
      }),
    );

    const page = await fetchImagePage(null);

    expect(search).toBe('');
    expect(page.data.map((image) => image.id)).toEqual(images.map((image) => image.id));
    expect(page.meta.next_cursor).toBe('next-cursor');
  });

  it('passes the cursor of the next page', async () => {
    let cursor: string | null = null;
    server.use(
      http.get(IMAGES_URL, ({ request }) => {
        cursor = new URL(request.url).searchParams.get('cursor');

        return HttpResponse.json(makeImagePagePayload([]));
      }),
    );

    await fetchImagePage('eyJpZCI6IjAxIn0');

    expect(cursor).toBe('eyJpZCI6IjAxIn0');
  });

  it('aborts the request when the signal is aborted', async () => {
    const controller = new AbortController();
    server.use(
      http.get(IMAGES_URL, async () => {
        controller.abort();
        // Never answers; only the abort can end the request.
        await new Promise(() => undefined);

        return HttpResponse.json(makeImagePagePayload([]));
      }),
    );

    await expect(fetchImagePage(null, controller.signal)).rejects.toMatchObject({
      code: 'ERR_CANCELED',
    });
  });

  it('rejects a response that does not match the schema', async () => {
    server.use(http.get(IMAGES_URL, () => HttpResponse.json({ data: [{ id: 1 }] })));

    await expect(fetchImagePage(null)).rejects.toBeInstanceOf(ZodError);
  });
});
