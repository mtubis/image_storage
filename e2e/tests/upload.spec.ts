import { expect, test } from '@playwright/test';
import { API_URL, deleteImage, type StoredImage } from './support/api';
import { readFixture, uniqueName } from './support/fixtures';

const uploaded: string[] = [];

test.beforeEach(async ({ page }) => {
  await page.goto('/');
  // The list must be there before an upload, or "on top" would be trivially true.
  await expect(page.getByRole('article')).not.toHaveCount(0);
});

test.afterEach(async ({ request }) => {
  for (const id of uploaded.splice(0)) {
    await deleteImage(request, id);
  }
});

test('a valid upload appears at the top of the list', async ({ page }) => {
  const name = uniqueName('upload', 'jpg');

  await page.getByLabel('Your name').fill('Anna Nowak');
  await page.getByLabel('Your e-mail').fill('anna.nowak@example.com');
  const file = page.getByLabel('Image', { exact: true });
  await file.setInputFiles({
    name,
    mimeType: 'image/jpeg',
    buffer: await readFixture('valid.jpg'),
  });
  const response = page.waitForResponse(
    (candidate) =>
      candidate.url() === `${API_URL}/images` && candidate.request().method() === 'POST',
  );
  await page.getByRole('button', { name: 'Upload', exact: true }).click();
  uploaded.push(((await (await response).json()) as { data: StoredImage }).data.id);

  await expect(page.getByText(`Uploaded ${name}.`)).toBeVisible();
  const first = page.getByRole('article').first();
  await expect(first).toHaveAccessibleName(name);
  await expect(first).toContainText('500 × 500 px');
  await expect(first).toContainText('Anna Nowak');
  // The form is ready for the next upload, including the file input, which can't be controlled.
  await expect(page.getByLabel('Your name')).toHaveValue('');
  await expect(file).toHaveValue('');
});

test('a too small image is rejected with a message', async ({ page }) => {
  // Aborted rather than only observed: a wrongly sent upload must not land in the shared database.
  let posted = false;
  await page.route(`${API_URL}/images`, (route) => {
    if (route.request().method() === 'POST') {
      posted = true;
      return route.abort();
    }
    return route.continue();
  });
  const name = uniqueName('too-narrow', 'jpg');

  await page.getByLabel('Your name').fill('Anna Nowak');
  await page.getByLabel('Your e-mail').fill('anna.nowak@example.com');
  const file = page.getByLabel('Image', { exact: true });
  await file.setInputFiles({
    name,
    mimeType: 'image/jpeg',
    buffer: await readFixture('too-narrow.jpg'),
  });
  await page.getByRole('button', { name: 'Upload', exact: true }).click();

  // Rendered only once the client-side validation has settled, so no POST can follow it.
  await expect(page.getByText(/it is 499 × 500 px/)).toBeVisible();
  await expect(file).toHaveAttribute('aria-invalid', 'true');
  expect(posted).toBe(false);
  await expect(page.getByRole('article', { name })).toHaveCount(0);
});
