import { z } from 'zod';
import { httpUrl } from '@/lib/httpUrl';

const envSchema = z.object({
  VITE_API_URL: httpUrl({
    error:
      'VITE_API_URL must be the absolute http(s) URL of the backend API, e.g. http://localhost:8000/api/v1.',
  }),
});

export interface Env {
  readonly apiUrl: string;
}

// A missing variable is `undefined` at runtime whatever `ImportMetaEnv` says, and would
// otherwise surface only as requests to "undefined/images".
export function parseEnv(raw: Record<string, unknown>): Env {
  const result = envSchema.safeParse(raw);
  if (!result.success) {
    throw new Error(`Invalid environment configuration: ${z.prettifyError(result.error)}`);
  }

  return { apiUrl: result.data.VITE_API_URL.replace(/\/+$/, '') };
}

export const env: Env = parseEnv(import.meta.env);
