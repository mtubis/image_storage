import { expect, test } from '@playwright/test';
import { API_URL, deleteImage, uploadImage } from './support/api';
import { uniqueName } from './support/fixtures';

test('deleting an image removes it from the list and the server', async ({ page, request }) => {
  const name = uniqueName('delete-me', 'png');
  const image = await uploadImage(request, { fixture: 'valid.png', name });

  try {
    await page.goto('/');
    const card = page.getByRole('article', { name });
    await card.getByRole('button', { name: `Delete ${name}` }).click();
    await card.getByRole('button', { name: 'Yes, delete' }).click();

    await expect(card).toHaveCount(0);
    await page.reload();
    await expect(page.getByRole('article').first()).toBeVisible();
    await expect(page.getByRole('article', { name })).toHaveCount(0);
    // Both files are gone: the original (download endpoint) and the thumbnail (served by nginx).
    expect((await request.get(`${API_URL}/images/${image.id}/download`)).status()).toBe(404);
    expect((await request.get(image.thumbnail_url)).status()).toBe(404);
  } finally {
    // Only matters when the test failed before the deletion; a 404 is accepted.
    await deleteImage(request, image.id);
  }
});
