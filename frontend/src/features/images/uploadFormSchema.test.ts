import { beforeEach, describe, expect, it, vi } from 'vitest';
import { uploadFormSchema, type UploadFormInput } from '@/features/images/uploadFormSchema';
import { readImageDimensions, type ImageDimensions } from '@/lib/readImageDimensions';

// jsdom can't decode images; readImageDimensions has its own tests.
vi.mock('@/lib/readImageDimensions');

function file(name: string, type: string, size = 1024): File {
  return new File([new Uint8Array(size)], name, { type });
}

function input(overrides: Partial<UploadFormInput> = {}): UploadFormInput {
  return {
    uploader_name: 'Jane Doe',
    uploader_email: 'jane@example.com',
    file: file('photo.jpg', 'image/jpeg'),
    ...overrides,
  };
}

// The first message per field, which is the one the form shows.
async function errorsOf(values: UploadFormInput): Promise<Record<string, string | undefined>> {
  const result = await uploadFormSchema.safeParseAsync(values);
  const errors: Record<string, string> = {};
  for (const issue of result.error?.issues ?? []) {
    errors[issue.path.join('.')] ??= issue.message;
  }

  return errors;
}

describe('uploadFormSchema', () => {
  let dimensions: ImageDimensions | null;

  beforeEach(() => {
    dimensions = { width: 500, height: 500 };
    vi.mocked(readImageDimensions).mockImplementation(() => Promise.resolve(dimensions));
  });

  it('accepts a valid upload and trims the text fields', async () => {
    const values = input({ uploader_name: '  Jane Doe ', uploader_email: ' jane@example.com ' });

    const parsed = await uploadFormSchema.parseAsync(values);

    expect(parsed).toEqual({
      ...values,
      uploader_name: 'Jane Doe',
      uploader_email: 'jane@example.com',
    });
  });

  describe('uploader_name', () => {
    it.each([
      ['empty', '', 'Enter your name.'],
      ['only spaces', '   ', 'Enter your name.'],
      [
        'longer than 100 characters',
        'a'.repeat(101),
        'The name must not be longer than 100 characters.',
      ],
      [
        'a control character',
        'Jane\u0007Doe',
        'The name must not contain control or text direction characters.',
      ],
      [
        'a bidi override',
        'Jane\u202EeoD',
        'The name must not contain control or text direction characters.',
      ],
      [
        'an Arabic letter mark',
        'Jane\u061C',
        'The name must not contain control or text direction characters.',
      ],
    ])('rejects a name that is %s', async (_case, name, message) => {
      expect((await errorsOf(input({ uploader_name: name }))).uploader_name).toBe(message);
    });

    // Counted in characters like the server (mb_strlen), not UTF-16 code units.
    it('accepts 100 characters outside the Basic Multilingual Plane', async () => {
      expect(await errorsOf(input({ uploader_name: '😀'.repeat(100) }))).toEqual({});
    });

    it('accepts letters of any script', async () => {
      expect(await errorsOf(input({ uploader_name: 'Zażółć Gęślą Jaźń' }))).toEqual({});
    });
  });

  describe('uploader_email', () => {
    it.each([
      ['empty', '', 'Enter your e-mail address.'],
      ['without a domain', 'jane@', 'Enter a valid e-mail address.'],
      ['without an @', 'jane.example.com', 'Enter a valid e-mail address.'],
      [
        'longer than 255 characters',
        `${'a'.repeat(64)}@${'b'.repeat(187)}.com`,
        'The e-mail address must not be longer than 255 characters.',
      ],
    ])('rejects an address that is %s', async (_case, email, message) => {
      expect((await errorsOf(input({ uploader_email: email }))).uploader_email).toBe(message);
    });

    // The server's RFC check is the final word; the client must not be stricter.
    it.each(['jane@localhost', 'jan@żółw.pl', 'zażółć@example.com'])(
      'accepts %s',
      async (email) => {
        expect(await errorsOf(input({ uploader_email: email }))).toEqual({});
      },
    );

    it('rejects an address with a space', async () => {
      expect(
        (await errorsOf(input({ uploader_email: 'jane doe@example.com' }))).uploader_email,
      ).toBe('Enter a valid e-mail address.');
    });
  });

  describe('file', () => {
    it('requires a file', async () => {
      expect((await errorsOf(input({ file: undefined }))).file).toBe('Choose an image to upload.');
    });

    it.each([
      ['photo.jpg', 'image/jpeg'],
      ['photo.jpeg', 'image/jpeg'],
      ['photo.png', 'image/png'],
      ['photo.webp', 'image/webp'],
      ['scan.tif', 'image/tiff'],
      ['scan.tiff', 'image/tiff'],
      ['drawing.bmp', 'image/bmp'],
      ['drawing.bmp', 'image/x-ms-bmp'],
      // Linux names for BMP: the extension is allowed.
      ['drawing.bmp', 'image/x-bmp'],
      ['drawing.bmp', 'image/x-windows-bmp'],
      // The content type decides: the server detects the type from the content, too.
      ['photo.jfif', 'image/jpeg'],
    ])('accepts %s (%s)', async (name, type) => {
      expect(await errorsOf(input({ file: file(name, type) }))).toEqual({});
    });

    // Where the system has no content type for the extension, the name is all there is.
    it.each(['scan.TIF', 'photo.jpg'])('accepts %s without a content type', async (name) => {
      expect(await errorsOf(input({ file: file(name, '') }))).toEqual({});
    });

    it.each([
      ['document.pdf', 'application/pdf'],
      ['animation.gif', 'image/gif'],
      ['vector.svg', 'image/svg+xml'],
      ['photo.heic', 'image/heic'],
      ['photo.jpg.exe', ''],
      ['noextension', ''],
    ])('rejects %s (%s)', async (name, type) => {
      expect((await errorsOf(input({ file: file(name, type) }))).file).toBe(
        'The file must be a JPG, PNG, WebP, TIFF or BMP image.',
      );
    });

    it('accepts a file of exactly 5 MiB', async () => {
      expect(
        await errorsOf(input({ file: file('photo.jpg', 'image/jpeg', 5 * 1024 ** 2) })),
      ).toEqual({});
    });

    it('rejects a file larger than 5 MiB', async () => {
      const errors = await errorsOf(
        input({ file: file('photo.jpg', 'image/jpeg', 5 * 1024 ** 2 + 1) }),
      );

      expect(errors.file).toBe('The file must not be larger than 5 MiB.');
    });

    it.each([
      [499, 500],
      [500, 499],
      [10_001, 500],
      [500, 10_001],
    ])('rejects an image of %i × %i px', async (width, height) => {
      dimensions = { width, height };

      expect((await errorsOf(input())).file).toBe(
        `The image must be between 500 × 500 and 10000 × 10000 px (it is ${String(width)} × ${String(height)} px).`,
      );
    });

    it('accepts an image of 10000 × 10000 px', async () => {
      dimensions = { width: 10_000, height: 10_000 };

      expect(await errorsOf(input())).toEqual({});
    });

    it('accepts an image the browser cannot decode; the server checks it', async () => {
      dimensions = null;

      expect(await errorsOf(input({ file: file('scan.tif', 'image/tiff') }))).toEqual({});
    });

    it('does not decode a file of the wrong type or size', async () => {
      await errorsOf(input({ file: file('document.pdf', 'application/pdf') }));
      await errorsOf(input({ file: file('photo.jpg', 'image/jpeg', 5 * 1024 ** 2 + 1) }));

      expect(readImageDimensions).not.toHaveBeenCalled();
    });
  });
});
