import { createHash } from 'node:crypto';
import { readFile } from 'node:fs/promises';
import { expect, test } from '@playwright/test';
import { deleteImage, uploadImage } from './support/api';
import { readFixture, uniqueName } from './support/fixtures';

// A digest fails with two readable values, where Buffer.equals() only says "false".
function sha256(contents: Buffer): string {
  return createHash('sha256').update(contents).digest('hex');
}

test('the download returns the original file under its original name', async ({
  page,
  request,
}) => {
  // Non-ASCII and a space: the name must survive Content-Disposition (filename*) unchanged.
  const name = uniqueName('zdjęcie ąę', 'jpg');
  // With EXIF and IPTC: the original is stored and served untouched, metadata included.
  const image = await uploadImage(request, { fixture: 'exif-iptc.jpg', name });

  try {
    await page.goto('/');
    const card = page.getByRole('article', { name });
    const downloadPromise = page.waitForEvent('download');
    await card.getByRole('link', { name: `Download ${name}` }).click();
    const download = await downloadPromise;

    expect(download.suggestedFilename()).toBe(name);
    const contents = await readFile(await download.path());
    expect(sha256(contents)).toBe(sha256(await readFixture('exif-iptc.jpg')));
  } finally {
    await deleteImage(request, image.id);
  }
});
