import { describe, expect, it } from 'vitest';
import { parseEnv } from '@/lib/env';

describe('parseEnv', () => {
  it('reads the API base URL', () => {
    expect(parseEnv({ VITE_API_URL: 'http://localhost:8000/api/v1' })).toEqual({
      apiUrl: 'http://localhost:8000/api/v1',
    });
  });

  it('accepts https', () => {
    expect(parseEnv({ VITE_API_URL: 'https://api.example.com/api/v1' }).apiUrl).toBe(
      'https://api.example.com/api/v1',
    );
  });

  // The value is also used outside axios (e.g. to build URLs), so it has one canonical form.
  it('strips trailing slashes', () => {
    expect(parseEnv({ VITE_API_URL: 'http://localhost:8000/api/v1//' }).apiUrl).toBe(
      'http://localhost:8000/api/v1',
    );
  });

  it.each([
    ['missing', {}],
    ['empty', { VITE_API_URL: '' }],
    ['relative', { VITE_API_URL: '/api/v1' }],
    ['not http(s)', { VITE_API_URL: 'ftp://localhost/api/v1' }],
    ['not a URL', { VITE_API_URL: 'localhost:8000' }],
  ])('rejects a %s VITE_API_URL with a message naming the variable', (_case, raw) => {
    expect(() => parseEnv(raw)).toThrow(/VITE_API_URL/);
  });
});
