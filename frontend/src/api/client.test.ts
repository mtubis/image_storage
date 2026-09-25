import { http, HttpResponse } from 'msw';
import { describe, expect, it } from 'vitest';
import { apiClient } from '@/api/client';
import { server } from '@/test/msw/server';

describe('apiClient', () => {
  it('sends requests to the configured API and asks for JSON', async () => {
    let accept: string | null = null;
    server.use(
      http.get('http://api.test/api/v1/images', ({ request }) => {
        accept = request.headers.get('Accept');

        return HttpResponse.json({ ok: true });
      }),
    );

    const response = await apiClient.get('/images');

    expect(response.data).toEqual({ ok: true });
    expect(accept).toBe('application/json');
  });
});
