import { QueryClientProvider, type QueryClient } from '@tanstack/react-query';
import { render, type RenderOptions, type RenderResult } from '@testing-library/react';
import type { ReactElement } from 'react';
import { createQueryClient } from '@/lib/queryClient';

// A fresh client per test keeps the cache from leaking between tests; no retries, so error
// states show up immediately instead of after the production backoff.
export function renderWithProviders(
  ui: ReactElement,
  options?: Omit<RenderOptions, 'wrapper'>,
): RenderResult & { queryClient: QueryClient } {
  const queryClient = createQueryClient({
    queries: { retry: false },
    mutations: { retry: false },
  });

  return {
    ...render(ui, {
      wrapper: ({ children }) => (
        <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
      ),
      ...options,
    }),
    queryClient,
  };
}
