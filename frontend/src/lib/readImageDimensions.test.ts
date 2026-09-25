import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { readImageDimensions } from '@/lib/readImageDimensions';

type Outcome = { width: number; height: number } | 'error';

// jsdom neither decodes images nor implements object URLs; this stand-in "loads" each file
// with the outcome configured for its name.
function stubImageDecoding(outcomes: Record<string, Outcome>) {
  const created = new Map<string, string>();
  const revoked: string[] = [];
  let loads = 0;

  vi.stubGlobal('URL', {
    createObjectURL: (file: File) => {
      const url = `blob:${String(created.size)}`;
      created.set(url, file.name);

      return url;
    },
    revokeObjectURL: (url: string) => {
      revoked.push(url);
    },
  });

  vi.stubGlobal(
    'Image',
    class {
      naturalWidth = 0;
      naturalHeight = 0;
      onload: (() => void) | null = null;
      onerror: (() => void) | null = null;

      set src(url: string) {
        loads++;
        const outcome = outcomes[created.get(url) ?? ''];
        setTimeout(() => {
          if (outcome === undefined || outcome === 'error') {
            this.onerror?.();

            return;
          }
          this.naturalWidth = outcome.width;
          this.naturalHeight = outcome.height;
          this.onload?.();
        });
      }
    },
  );

  return { revoked: () => revoked, created: () => [...created.keys()], loads: () => loads };
}

describe('readImageDimensions', () => {
  let decoding: ReturnType<typeof stubImageDecoding>;

  beforeEach(() => {
    decoding = stubImageDecoding({
      'photo.jpg': { width: 1920, height: 1080 },
      'scan.tif': 'error',
      'empty.svg': { width: 0, height: 0 },
    });
  });

  afterEach(() => {
    // Every object URL is released, whatever the outcome.
    expect(decoding.revoked()).toEqual(decoding.created());
  });

  it('reads the dimensions of an image the browser can decode', async () => {
    await expect(readImageDimensions(new File(['x'], 'photo.jpg'))).resolves.toEqual({
      width: 1920,
      height: 1080,
    });
  });

  it('returns null when the browser cannot decode the file (e.g. TIFF)', async () => {
    await expect(readImageDimensions(new File(['x'], 'scan.tif'))).resolves.toBeNull();
  });

  it('returns null for an image without intrinsic dimensions', async () => {
    await expect(readImageDimensions(new File(['x'], 'empty.svg'))).resolves.toBeNull();
  });

  // Form validation runs on every change of any field; the file must not be decoded each time.
  it('decodes each file only once', async () => {
    const file = new File(['x'], 'photo.jpg');

    await readImageDimensions(file);
    await readImageDimensions(file);

    expect(decoding.loads()).toBe(1);
  });
});
