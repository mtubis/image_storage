import { expect, test, type Locator } from '@playwright/test';
import { API_URL } from './support/api';

async function boxOf(locator: Locator): Promise<{ width: number; height: number }> {
  const box = await locator.boundingBox();
  if (box === null) {
    throw new Error('The element is not visible.');
  }

  return box;
}

// Rely on the seeded data (make e2e): seed-01 … seed-15, more than one page of 10, alternating
// 800 × 600 and 600 × 800. seed-01 is the oldest: seeded within one second, ties in `created_at`
// are broken by the ULID, which is monotonic within the seeding process. Other tests delete what
// they store, so the seeded images stay the first 15.

test('scrolling to the end loads the next page', async ({ page }) => {
  const nextPages: string[] = [];
  page.on('request', (request) => {
    if (request.url().includes('cursor=')) {
      nextPages.push(request.url());
    }
  });
  const firstPage = page.waitForResponse(`${API_URL}/images`);
  await page.goto('/');
  await firstPage;
  const cards = page.getByRole('article');
  const oldest = page.getByRole('article', { name: 'seed-01.jpg' });

  // Nothing loads by itself: the end of the list is out of view.
  await expect(cards).toHaveCount(10);
  await expect(page.getByRole('button', { name: 'Load more' })).toBeVisible();
  expect(nextPages).toEqual([]);

  // Scrolled like a user, not via the "Load more" button: the sentinel must trigger the load.
  await expect(async () => {
    await page.mouse.wheel(0, 10_000);
    await expect(oldest).toBeVisible({ timeout: 1_000 });
  }).toPass({ timeout: 15_000 });
  expect(nextPages).not.toEqual([]);
  await expect(page.getByText('All images loaded.')).toBeVisible();
});

// Regression (step 3.5): a portrait thumbnail made its box taller than wide, and so its card
// taller than a landscape neighbour's.
test('a portrait thumbnail stays inside a square box', async ({ page }) => {
  await page.goto('/');
  const portrait = page.getByRole('article').filter({ hasText: '600 × 800 px' }).first();
  const landscape = page.getByRole('article').filter({ hasText: '800 × 600 px' }).first();
  const thumbnail = portrait.getByRole('img');
  // Lazily loaded: only an image in (or near) the viewport is fetched.
  await thumbnail.scrollIntoViewIfNeeded();
  await expect
    .poll(() => thumbnail.evaluate((image: HTMLImageElement) => image.naturalWidth))
    .toBeGreaterThan(0);

  const portraitBox = await boxOf(thumbnail.locator('..'));
  const landscapeBox = await boxOf(landscape.getByRole('img').locator('..'));
  expect(Math.abs(portraitBox.width - portraitBox.height)).toBeLessThanOrEqual(1);
  expect(Math.abs(portraitBox.height - landscapeBox.height)).toBeLessThanOrEqual(1);
});
