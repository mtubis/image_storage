import { apiClient } from '@/api/client';
import { imagePageSchema, type ImagePage } from '@/api/schemas';

// `cursor` is the opaque `meta.next_cursor` of the previous page; null for the first page.
export async function fetchImagePage(
  cursor: string | null,
  signal?: AbortSignal,
): Promise<ImagePage> {
  const response = await apiClient.get<unknown>('/images', {
    params: cursor === null ? {} : { cursor },
    signal,
  });

  return imagePageSchema.parse(response.data);
}
