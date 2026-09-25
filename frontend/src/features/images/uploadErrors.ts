import { AxiosError, isAxiosError } from 'axios';
import { validationErrorSchema } from '@/api/schemas';

// In form order.
export const UPLOAD_FIELDS = ['uploader_name', 'uploader_email', 'file'] as const;

export type UploadField = (typeof UPLOAD_FIELDS)[number];

export interface UploadErrors {
  // The first message per form field.
  fields: [UploadField, string][];
  // Everything that belongs to no field.
  form: string | null;
}

function isUploadField(key: string): key is UploadField {
  return (UPLOAD_FIELDS as readonly string[]).includes(key);
}

// The list is refreshed after every attempt (useUploadImage), so it shows the answer.
const MAYBE_STORED = 'The image may have been uploaded anyway; check the list before trying again.';

// Translates a failed upload into messages for the form. The server's 422 messages are shown
// as they are: they are written for users and name the field in its label's words.
export function toUploadErrors(error: unknown): UploadErrors {
  if (!isAxiosError(error)) {
    // E.g. a 201 whose body failed schema parsing: the image may well have been stored.
    return { fields: [], form: MAYBE_STORED };
  }

  const { response } = error;
  // The body may have reached the server and been stored before the client gave up.
  if (error.code === AxiosError.ECONNABORTED || error.code === AxiosError.ETIMEDOUT) {
    return { fields: [], form: `The upload took too long. ${MAYBE_STORED}` };
  }
  if (response === undefined) {
    return { fields: [], form: 'Could not reach the server. Check your connection and try again.' };
  }

  if (response.status === 413) {
    // Rejected by nginx or PHP before validation, so it comes without field errors.
    return { fields: [['file', 'The file is too large for the server.']], form: null };
  }
  if (response.status === 429) {
    // The server limits uploads per client; nothing was stored.
    return {
      fields: [],
      form: 'Too many uploads in a short time. Please wait a minute and try again.',
    };
  }

  const validation = validationErrorSchema.safeParse(response.data);
  if (response.status === 422 && validation.success) {
    const fields: [UploadField, string][] = [];
    const other: string[] = [];
    for (const [key, messages] of Object.entries(validation.data.errors)) {
      if (messages[0] === undefined) {
        continue;
      }
      if (isUploadField(key)) {
        fields.push([key, messages[0]]);
      } else {
        other.push(...messages);
      }
    }

    // A 422 without any messages still says something went wrong.
    const form = other.length > 0 ? other.join(' ') : null;

    return { fields, form: fields.length === 0 ? (form ?? validation.data.message) : form };
  }

  return { fields: [], form: 'The upload failed. Please try again.' };
}
