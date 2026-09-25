import { http, HttpResponse, type RequestHandler } from 'msw';
import { env } from '@/lib/env';
import { makeImagePagePayload } from '@/test/factories';

export const IMAGES_URL = `${env.apiUrl}/images`;

// Default happy-path API handlers; individual tests override them via `server.use()`.
export const handlers: RequestHandler[] = [
  http.get(IMAGES_URL, () => HttpResponse.json(makeImagePagePayload([]))),
];
