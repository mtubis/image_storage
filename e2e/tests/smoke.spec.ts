import { expect, test } from '@playwright/test';

// One pass through the whole chain: SPA → CORS → API → database (seeded) → thumbnails via nginx.
test('shows the seeded images with their thumbnails', async ({ page }) => {
  await page.goto('/');

  await expect(page.getByRole('heading', { name: 'Image Storage', level: 1 })).toBeVisible();
  const newest = page.getByRole('article', { name: 'seed-15.jpg' });
  await expect(newest).toBeVisible();

  const thumbnail = newest.getByRole('img', { name: 'Thumbnail of seed-15.jpg' });
  // A broken image is still "visible"; only a decoded one has a natural width.
  await expect
    .poll(() => thumbnail.evaluate((image: HTMLImageElement) => image.naturalWidth))
    .toBeGreaterThan(0);
});
