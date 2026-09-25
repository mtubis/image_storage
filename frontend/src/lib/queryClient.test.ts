import { AxiosError, AxiosHeaders, type AxiosResponse } from 'axios';
import { describe, expect, it } from 'vitest';
import { imageSchema } from '@/api/schemas';
import { shouldRetry } from '@/lib/queryClient';

// Mirrors axios' own settle(): 4xx is ERR_BAD_REQUEST, 5xx is ERR_BAD_RESPONSE.
function httpError(status: number): AxiosError {
  const response: AxiosResponse = {
    data: {},
    status,
    statusText: '',
    headers: {},
    config: { headers: new AxiosHeaders() },
  };
  const code = status < 500 ? AxiosError.ERR_BAD_REQUEST : AxiosError.ERR_BAD_RESPONSE;

  return new AxiosError('Request failed', code, undefined, undefined, response);
}

describe('shouldRetry', () => {
  it.each([400, 404, 413, 422])(
    'does not retry a %d, which would fail the same way again',
    (status) => {
      expect(shouldRetry(0, httpError(status))).toBe(false);
    },
  );

  it.each([500, 502, 503])('retries a %d', (status) => {
    expect(shouldRetry(0, httpError(status))).toBe(true);
  });

  it('retries a network error, which has no response', () => {
    expect(shouldRetry(0, new AxiosError('Network Error', AxiosError.ERR_NETWORK))).toBe(true);
  });

  it('retries a timeout', () => {
    expect(shouldRetry(0, new AxiosError('timeout exceeded', AxiosError.ECONNABORTED))).toBe(true);
  });

  it('does not retry a response that failed schema parsing', () => {
    const { error } = imageSchema.safeParse({});

    expect(shouldRetry(0, error)).toBe(false);
  });

  it('gives up after two retries', () => {
    expect(shouldRetry(1, httpError(503))).toBe(true);
    expect(shouldRetry(2, httpError(503))).toBe(false);
  });
});
