import type { RequestHandler } from 'msw';

// Default happy-path API handlers; individual tests override them via `server.use()`.
export const handlers: RequestHandler[] = [];
