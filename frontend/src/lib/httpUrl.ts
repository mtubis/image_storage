import { z } from 'zod';

// An absolute http(s) URL. zod's own `z.httpUrl()` requires a dotted domain and so rejects
// `localhost`, where the API runs in development; any valid hostname or IPv4 address is
// accepted here (IPv6 literals are not, as no deployment of this app uses them).
export function httpUrl(params?: { error?: string }): z.ZodURL {
  return z.url({ protocol: /^https?$/, hostname: z.regexes.hostname, ...params });
}
