import { http, HttpResponse } from 'msw';
import { describe, expect, it } from 'vitest';
import { ZodError } from 'zod';
import { fetchImagePage, uploadImage } from '@/api/images';
import { makeImagePagePayload, makeImagePayload, makeImagePayloads } from '@/test/factories';
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

describe('uploadImage', () => {
  const file = new File(['image bytes'], 'photo.jpg', { type: 'image/jpeg' });

  it('posts the file and the uploader as multipart form data', async () => {
    const image = makeImagePayload({ original_name: 'photo.jpg', temperature_c: null });
    let fields: Record<string, FormDataEntryValue> = {};
    server.use(
      http.post(IMAGES_URL, async ({ request }) => {
        fields = Object.fromEntries(await request.formData());

        return HttpResponse.json({ data: image }, { status: 201 });
      }),
    );

    const uploaded = await uploadImage({
      file,
      uploader_name: 'Jane Doe',
      uploader_email: 'jane@example.com',
    });

    expect(uploaded.id).toBe(image.id);
    expect(fields.uploader_name).toBe('Jane Doe');
    expect(fields.uploader_email).toBe('jane@example.com');
    expect(fields.file).toBeInstanceOf(File);
    expect((fields.file as File).name).toBe('photo.jpg');
    expect(await (fields.file as File).text()).toBe('image bytes');
  });

  it('reports the upload progress', async () => {
    server.use(
      http.post(IMAGES_URL, () => HttpResponse.json({ data: makeImagePayload() }, { status: 201 })),
    );
    const progress: number[] = [];

    await uploadImage(
      { file, uploader_name: 'Jane Doe', uploader_email: 'jane@example.com' },
      { onProgress: (fraction) => progress.push(fraction) },
    );

    expect(progress.at(-1)).toBe(1);
  });

  it('rejects a response that does not match the schema', async () => {
    server.use(
      http.post(IMAGES_URL, () => HttpResponse.json({ data: { id: 1 } }, { status: 201 })),
    );

    await expect(
      uploadImage({ file, uploader_name: 'Jane Doe', uploader_email: 'jane@example.com' }),
    ).rejects.toBeInstanceOf(ZodError);
  });
});
