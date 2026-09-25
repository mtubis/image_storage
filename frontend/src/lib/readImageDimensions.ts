export interface ImageDimensions {
  width: number;
  height: number;
}

// Keyed by the File object: the same selection is looked up on every form validation.
const cache = new WeakMap<Blob, Promise<ImageDimensions | null>>();

// The pixel dimensions of an image file, or null when this browser can't decode it (TIFF in
// most browsers, a damaged file). Callers treat null as "unknown", not as invalid. Safari does
// decode TIFF and reports its first page, which is also the one the server measures.
export function readImageDimensions(file: Blob): Promise<ImageDimensions | null> {
  let dimensions = cache.get(file);
  if (dimensions === undefined) {
    dimensions = load(file);
    cache.set(file, dimensions);
  }

  return dimensions;
}

// An <img> reports its natural size once loaded, without decode(), which would allocate every
// pixel (up to 400 MB for a 10000 × 10000 image). Browsers apply the EXIF orientation, so width
// and height may be swapped compared to the server's reading; the limits are the same for both.
function load(file: Blob): Promise<ImageDimensions | null> {
  return new Promise((resolve) => {
    const url = URL.createObjectURL(file);
    const image = new Image();

    const finish = (dimensions: ImageDimensions | null) => {
      URL.revokeObjectURL(url);
      resolve(dimensions);
    };

    image.onload = () => {
      const { naturalWidth: width, naturalHeight: height } = image;
      finish(width > 0 && height > 0 ? { width, height } : null);
    };
    image.onerror = () => {
      finish(null);
    };
    image.src = url;
  });
}
