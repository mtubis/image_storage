import { z } from 'zod';
import { httpUrl } from '@/lib/httpUrl';

// The single definition of the API's shapes. Responses are parsed at the boundary, so
// components only ever see validated data. Objects strip unknown keys: the UI gets exactly
// the fields modelled here.

export const imageSchema = z.object({
  id: z.ulid(),
  original_name: z.string(),
  // Not an enum: the server decides which formats are allowed.
  extension: z.string(),
  size_bytes: z.int().nonnegative(),
  width: z.int().positive(),
  height: z.int().positive(),
  thumbnail_url: httpUrl(),
  download_url: httpUrl(),
  uploader_name: z.string(),
});

// Not `Image`, which would shadow the DOM's Image constructor.
export type ApiImage = z.infer<typeof imageSchema>;

// Laravel's cursor paginator; only what infinite scroll needs is modelled.
export const imagePageSchema = z.object({
  data: z.array(imageSchema),
  meta: z.object({
    next_cursor: z.string().nullable(),
  }),
});

export type ImagePage = z.infer<typeof imagePageSchema>;

// A single image as returned by the upload: Laravel wraps a resource in `data`.
export const imageResponseSchema = z.object({
  data: imageSchema,
});

// Laravel's default 422 body: messages keyed by the request field name.
export const validationErrorSchema = z.object({
  message: z.string(),
  errors: z.record(z.string(), z.array(z.string())),
});
