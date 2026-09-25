// Mirrors backend/config/images.php. The apps share no code, so the values are repeated here;
// the server stays the source of truth and these checks only spare the user a round trip.

// Content type (as the browser reports it) => accepted file name extensions.
export const ALLOWED_TYPES: Readonly<Record<string, readonly string[]>> = {
  'image/jpeg': ['jpg', 'jpeg'],
  'image/png': ['png'],
  'image/webp': ['webp'],
  'image/tiff': ['tif', 'tiff'],
  'image/bmp': ['bmp'],
  // What some systems report for BMP. The server accepts it too: its `mimes` rule maps the
  // detected type to the extension (Symfony's guessExtension()), and bmp is allowed.
  'image/x-ms-bmp': ['bmp'],
};

export const ALLOWED_TYPES_LABEL = 'JPG, PNG, WebP, TIFF or BMP';

// 5 MB as the server counts it: 5120 KiB.
export const MAX_FILE_SIZE_BYTES = 5 * 1024 ** 2;

export const MIN_WIDTH = 500;
export const MIN_HEIGHT = 500;
export const MAX_WIDTH = 10_000;
export const MAX_HEIGHT = 10_000;

// The `accept` attribute of the file input: types and extensions, since some systems don't
// map every extension (e.g. .tif) to a content type.
export const ACCEPT = [
  ...Object.keys(ALLOWED_TYPES),
  ...new Set(
    Object.values(ALLOWED_TYPES)
      .flat()
      .map((extension) => `.${extension}`),
  ),
].join(',');
