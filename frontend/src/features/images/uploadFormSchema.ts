import { z } from 'zod';
import {
  ALLOWED_TYPES,
  ALLOWED_TYPES_LABEL,
  MAX_FILE_SIZE_BYTES,
  MAX_HEIGHT,
  MAX_WIDTH,
  MIN_HEIGHT,
  MIN_WIDTH,
} from '@/features/images/uploadConstraints';
import { formatBytes } from '@/lib/formatBytes';
import { readImageDimensions } from '@/lib/readImageDimensions';

// Control and bidirectional formatting characters, as rejected by the server
// (App\Support\UploadedFilename::UNSAFE_CHARACTERS).
const UNSAFE_CHARACTERS = /[\p{Cc}\u061C\u200E\u200F\u202A-\u202E\u2066-\u2069]/u;

// In code points, as the server counts (mb_strlen); `.length` would count an emoji twice.
function characterCount(value: string): number {
  // eslint-disable-next-line @typescript-eslint/no-misused-spread -- code points, not graphemes, are wanted
  return [...value].length;
}

// Either is enough: browsers derive the type from the name through the system's own table, which
// may not know an extension (empty type) or use another name for it (image/x-bmp on Linux).
// The server detects the type from the content either way.
function hasAllowedType(file: File): boolean {
  const extension = /\.([^.]+)$/.exec(file.name)?.[1]?.toLowerCase();

  return (
    Object.hasOwn(ALLOWED_TYPES, file.type) ||
    (extension !== undefined && Object.values(ALLOWED_TYPES).flat().includes(extension))
  );
}

// Field names are the API's, so a server 422 maps onto them one to one.
export const uploadFormSchema = z.object({
  uploader_name: z
    .string()
    .trim()
    .min(1, 'Enter your name.')
    .refine(
      (name) => characterCount(name) <= 100,
      'The name must not be longer than 100 characters.',
    )
    .refine(
      (name) => !UNSAFE_CHARACTERS.test(name),
      'The name must not contain control or text direction characters.',
    ),
  uploader_email: z
    .string()
    .trim()
    .min(1, 'Enter your e-mail address.')
    .refine(
      (email) => characterCount(email) <= 255,
      'The e-mail address must not be longer than 255 characters.',
    )
    // Only catches obvious slips. The server's RFC check decides; z.email() and the HTML5 rule
    // would reject addresses it accepts (international domains, UTF-8 or quoted local parts).
    .regex(/^[^\s@]+@[^\s@]+$/u, 'Enter a valid e-mail address.'),
  file: z
    .instanceof(File, { error: 'Choose an image to upload.' })
    // Aborting: a file of the wrong type or size is not worth decoding.
    .refine(hasAllowedType, {
      error: `The file must be a ${ALLOWED_TYPES_LABEL} image.`,
      abort: true,
    })
    // Without the actual size: just over the limit, both would round to "5 MiB".
    .refine((file) => file.size <= MAX_FILE_SIZE_BYTES, {
      error: `The file must not be larger than ${formatBytes(MAX_FILE_SIZE_BYTES)}.`,
      abort: true,
    })
    .check(async (ctx) => {
      const dimensions = await readImageDimensions(ctx.value);
      // Undecodable here (e.g. TIFF) says nothing about the file; the server decides.
      if (dimensions === null) {
        return;
      }
      const { width, height } = dimensions;
      if (width < MIN_WIDTH || height < MIN_HEIGHT || width > MAX_WIDTH || height > MAX_HEIGHT) {
        ctx.issues.push({
          code: 'custom',
          input: ctx.value,
          message: `The image must be between ${String(MIN_WIDTH)} × ${String(MIN_HEIGHT)} and ${String(MAX_WIDTH)} × ${String(MAX_HEIGHT)} px (it is ${String(width)} × ${String(height)} px).`,
        });
      }
    }),
});

export type UploadFormInput = z.input<typeof uploadFormSchema>;
export type UploadFormValues = z.output<typeof uploadFormSchema>;
