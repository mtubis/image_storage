import axios from 'axios';
import { env } from '@/lib/env';

export const apiClient = axios.create({
  baseURL: env.apiUrl,
  // Makes Laravel answer with JSON (e.g. a 422 instead of a redirect) for every request.
  headers: { Accept: 'application/json' },
  // Without it a hanging request keeps a loading state forever and never reaches the retry
  // policy. Uploads override it per request.
  timeout: 15_000,
});
