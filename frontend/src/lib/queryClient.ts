import { QueryClient, type DefaultOptions } from '@tanstack/react-query';
import { isAxiosError } from 'axios';

const MAX_RETRIES = 2;

// Only transient failures are worth retrying: a network error, a timeout or a 5xx. A 4xx or a
// response that fails schema parsing would fail the same way again and only delay the error
// state. 408 and 429 are not retried either: the API sends neither today (no throttling).
export function shouldRetry(failureCount: number, error: unknown): boolean {
  if (failureCount >= MAX_RETRIES || !isAxiosError(error)) {
    return false;
  }

  return error.response === undefined || error.response.status >= 500;
}

// Tests pass overrides (no retries) but otherwise run with the production defaults.
export function createQueryClient(overrides: DefaultOptions = {}): QueryClient {
  return new QueryClient({
    defaultOptions: {
      queries: {
        staleTime: 30_000,
        retry: shouldRetry,
        ...overrides.queries,
      },
      mutations: overrides.mutations ?? {},
    },
  });
}
