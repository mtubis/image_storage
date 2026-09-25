import { apiClient } from '@/api/client';
import { imagePageSchema, imageResponseSchema, type ApiImage, type ImagePage } from '@/api/schemas';

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

export interface UploadImageInput {
  file: File;
  uploader_name: string;
  uploader_email: string;
}

interface UploadOptions {
  // Fraction of the request body sent so far, from 0 to 1.
  onProgress?: (fraction: number) => void;
}

// 5 MB over a slow mobile uplink takes far longer than the client's 15 s default.
const UPLOAD_TIMEOUT_MS = 120_000;

export async function uploadImage(
  input: UploadImageInput,
  { onProgress }: UploadOptions = {},
): Promise<ApiImage> {
  const body = new FormData();
  body.append('file', input.file);
  body.append('uploader_name', input.uploader_name);
  body.append('uploader_email', input.uploader_email);

  // No Content-Type header: axios lets the browser set multipart/form-data with its boundary.
  const response = await apiClient.post<unknown>('/images', body, {
    timeout: UPLOAD_TIMEOUT_MS,
    onUploadProgress: ({ progress }) => {
      // Defensive: undefined only when the total size is unknown, unlike for a FormData body.
      if (progress !== undefined) {
        onProgress?.(progress);
      }
    },
  });

  return imageResponseSchema.parse(response.data).data;
}

export async function deleteImage(id: string): Promise<void> {
  await apiClient.delete(`/images/${encodeURIComponent(id)}`);
}
