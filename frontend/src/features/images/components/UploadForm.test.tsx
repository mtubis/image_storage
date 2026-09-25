import { screen, waitFor } from '@testing-library/react';
import userEvent, { type UserEvent } from '@testing-library/user-event';
import { http, HttpResponse, type JsonBodyType } from 'msw';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { App } from '@/App';
import { UploadForm } from '@/features/images/components/UploadForm';
import { readImageDimensions, type ImageDimensions } from '@/lib/readImageDimensions';
import { makeImagePagePayload, makeImagePayload } from '@/test/factories';
import { IMAGES_URL } from '@/test/msw/handlers';
import { server } from '@/test/msw/server';
import { renderWithProviders } from '@/test/render';

// jsdom can't decode images; readImageDimensions has its own tests.
vi.mock('@/lib/readImageDimensions');

function jpeg(name = 'photo.jpg', size = 2048): File {
  return new File([new Uint8Array(size)], name, { type: 'image/jpeg' });
}

function field(label: string): HTMLInputElement {
  return screen.getByLabelText(label);
}

async function fillIn(user: UserEvent, file: File = jpeg()) {
  await user.type(field('Your name'), 'Jane Doe');
  await user.type(field('Your e-mail'), 'jane@example.com');
  await user.upload(field('Image'), file);
}

// Lets the promise callbacks of a resolved check run; nothing in the form waits longer.
async function settle() {
  await new Promise((resolve) => setTimeout(resolve, 50));
}

function submit(user: UserEvent) {
  return user.click(screen.getByRole('button', { name: 'Upload' }));
}

// Answers every upload with the given response and records each request's form fields.
function serveUpload(status: number, body: JsonBodyType) {
  const requests: Record<string, FormDataEntryValue>[] = [];
  server.use(
    http.post(IMAGES_URL, async ({ request }) => {
      requests.push(Object.fromEntries(await request.formData()));

      return HttpResponse.json(body, { status });
    }),
  );

  return requests;
}

describe('UploadForm', () => {
  beforeEach(() => {
    vi.mocked(readImageDimensions).mockResolvedValue({ width: 1000, height: 800 });
  });

  it('labels every field and states the file requirements', () => {
    renderWithProviders(<UploadForm />);

    expect(screen.getByRole('heading', { level: 2, name: 'Upload an image' })).toBeInTheDocument();
    expect(field('Your name')).toHaveAttribute('autocomplete', 'name');
    expect(field('Your e-mail')).toHaveAttribute('type', 'email');
    expect(field('Image')).toHaveAccessibleDescription(
      'JPG, PNG, WebP, TIFF or BMP, up to 5 MiB, at least 500 × 500 px.',
    );
    expect(field('Image')).toHaveAttribute('accept', expect.stringContaining('.tif'));
  });

  it('reports every missing field without sending anything', async () => {
    const requests = serveUpload(201, { data: makeImagePayload() });
    const user = userEvent.setup();
    renderWithProviders(<UploadForm />);

    await submit(user);

    expect(await screen.findByText('Enter your name.')).toBeInTheDocument();
    expect(screen.getByText('Enter your e-mail address.')).toBeInTheDocument();
    expect(screen.getByText('Choose an image to upload.')).toBeInTheDocument();
    expect(field('Your name')).toHaveAttribute('aria-invalid', 'true');
    expect(field('Your name')).toHaveAccessibleDescription('Enter your name.');
    expect(field('Your name')).toHaveFocus();
    expect(requests).toHaveLength(0);
  });

  it('reports an invalid e-mail address', async () => {
    const user = userEvent.setup();
    renderWithProviders(<UploadForm />);
    await fillIn(user);
    await user.clear(field('Your e-mail'));
    await user.type(field('Your e-mail'), 'jane@');

    await submit(user);

    expect(await screen.findByText('Enter a valid e-mail address.')).toBeInTheDocument();
  });

  describe('checks the chosen file right away', () => {
    it('rejects a file that is not a supported image', async () => {
      // As if the user switched the file dialog to "All files".
      const user = userEvent.setup({ applyAccept: false });
      renderWithProviders(<UploadForm />);

      await user.upload(
        field('Image'),
        new File(['%PDF'], 'document.pdf', { type: 'application/pdf' }),
      );

      expect(
        await screen.findByText('The file must be a JPG, PNG, WebP, TIFF or BMP image.'),
      ).toBeInTheDocument();
      expect(field('Image')).toHaveAttribute('aria-invalid', 'true');
    });

    it('rejects a file larger than 5 MiB', async () => {
      const user = userEvent.setup();
      renderWithProviders(<UploadForm />);

      await user.upload(field('Image'), jpeg('big.jpg', 5 * 1024 ** 2 + 1));

      expect(
        await screen.findByText('The file must not be larger than 5 MiB.'),
      ).toBeInTheDocument();
    });

    it('rejects an image smaller than 500 × 500 px', async () => {
      vi.mocked(readImageDimensions).mockResolvedValue({ width: 499, height: 800 });
      const user = userEvent.setup();
      renderWithProviders(<UploadForm />);

      await user.upload(field('Image'), jpeg());

      expect(
        await screen.findByText(
          'The image must be between 500 × 500 and 10000 × 10000 px (it is 499 × 800 px).',
        ),
      ).toBeInTheDocument();
    });

    it('clears the error when a valid file is chosen instead', async () => {
      vi.mocked(readImageDimensions).mockImplementation((file) =>
        Promise.resolve(
          (file as File).name === 'small.jpg'
            ? { width: 100, height: 100 }
            : { width: 1000, height: 800 },
        ),
      );
      const user = userEvent.setup();
      renderWithProviders(<UploadForm />);
      await user.upload(field('Image'), jpeg('small.jpg'));
      await screen.findByText(/The image must be between/);

      await user.upload(field('Image'), jpeg('large.jpg'));

      await waitFor(() => {
        expect(screen.queryByText(/The image must be between/)).not.toBeInTheDocument();
      });
      expect(field('Image')).toHaveAttribute('aria-invalid', 'false');
    });
  });

  it('reports on the file chosen last when an earlier check finishes after it', async () => {
    // One pending check per file, as the real function caches its promise.
    const pending = new Map<string, (dimensions: ImageDimensions) => void>();
    const checks = new Map<string, Promise<ImageDimensions>>();
    vi.mocked(readImageDimensions).mockImplementation((file) => {
      const name = (file as File).name;
      const check =
        checks.get(name) ??
        new Promise<ImageDimensions>((resolve) => {
          pending.set(name, resolve);
        });
      checks.set(name, check);

      return check;
    });
    const user = userEvent.setup();
    renderWithProviders(<UploadForm />);
    await user.upload(field('Image'), jpeg('large.jpg'));
    await user.upload(field('Image'), jpeg('small.jpg'));

    pending.get('small.jpg')?.({ width: 100, height: 100 });
    expect(await screen.findByText(/The image must be between/)).toBeInTheDocument();
    pending.get('large.jpg')?.({ width: 1000, height: 800 });
    await settle();

    expect(screen.getByText(/The image must be between/)).toBeInTheDocument();
    expect(field('Image')).toHaveAttribute('aria-invalid', 'true');
  });

  it('announces a file error, which appears while the focus stays on the input', async () => {
    const user = userEvent.setup({ applyAccept: false });
    renderWithProviders(<UploadForm />);

    await user.upload(field('Image'), new File(['GIF89a'], 'animation.gif', { type: 'image/gif' }));

    const error = await screen.findByText('The file must be a JPG, PNG, WebP, TIFF or BMP image.');
    expect(error.closest('[aria-live="polite"]')).not.toBeNull();
  });

  it('marks every field as required', () => {
    renderWithProviders(<UploadForm />);

    expect(screen.getByText('All fields are required.')).toBeInTheDocument();
    for (const label of ['Your name', 'Your e-mail', 'Image']) {
      expect(field(label)).toBeRequired();
    }
  });

  // Most browsers can't decode TIFF; the server checks its dimensions.
  it('uploads an image whose dimensions the browser cannot read', async () => {
    vi.mocked(readImageDimensions).mockResolvedValue(null);
    const requests = serveUpload(201, { data: makeImagePayload({ original_name: 'scan.tif' }) });
    const user = userEvent.setup();
    renderWithProviders(<UploadForm />);

    await fillIn(user, new File([new Uint8Array(2048)], 'scan.tif', { type: 'image/tiff' }));
    await submit(user);

    expect(await screen.findByText('Uploaded scan.tif.')).toBeInTheDocument();
    expect(requests).toHaveLength(1);
  });

  it('uploads the image, resets the form and shows the image in the list', async () => {
    const uploaded = makeImagePayload({ original_name: 'holiday.jpg', temperature_c: null });
    let listed: ReturnType<typeof makeImagePayload>[] = [];
    server.use(http.get(IMAGES_URL, () => HttpResponse.json(makeImagePagePayload(listed))));
    const requests: Record<string, FormDataEntryValue>[] = [];
    server.use(
      http.post(IMAGES_URL, async ({ request }) => {
        requests.push(Object.fromEntries(await request.formData()));
        listed = [uploaded];

        return HttpResponse.json({ data: uploaded }, { status: 201 });
      }),
    );
    const user = userEvent.setup();
    renderWithProviders(<App />);
    expect(await screen.findByText('No images uploaded yet.')).toBeInTheDocument();

    await fillIn(user, jpeg('holiday.jpg'));
    // Sent trimmed, as the server would store it.
    await user.type(field('Your name'), '  ');
    await submit(user);

    expect(await screen.findByText('Uploaded holiday.jpg.')).toBeInTheDocument();
    expect(requests).toHaveLength(1);
    expect(requests[0]?.uploader_name).toBe('Jane Doe');
    expect(requests[0]?.uploader_email).toBe('jane@example.com');
    expect((requests[0]?.file as File).name).toBe('holiday.jpg');
    expect(field('Your name')).toHaveValue('');
    expect(field('Your e-mail')).toHaveValue('');
    expect(field('Image').files).toHaveLength(0);
    expect(field('Image')).toHaveValue('');
    expect(await screen.findByRole('article', { name: 'holiday.jpg' })).toBeInTheDocument();
  });

  it('shows the progress and ignores further submits while uploading', async () => {
    let finish: (() => void) | undefined;
    let requestCount = 0;
    server.use(
      http.post(IMAGES_URL, async () => {
        requestCount++;
        await new Promise<void>((resolve) => {
          finish = resolve;
        });

        return HttpResponse.json({ data: makeImagePayload() }, { status: 201 });
      }),
    );
    const user = userEvent.setup();
    renderWithProviders(<UploadForm />);
    await fillIn(user);

    await submit(user);

    const button = await screen.findByRole('button', { name: 'Uploading…' });
    expect(button).toHaveAttribute('aria-disabled', 'true');
    expect(screen.getByRole('progressbar', { name: 'Upload progress' })).toBeInTheDocument();
    await user.click(button);
    await waitFor(() => {
      expect(finish).toBeDefined();
    });
    expect(requestCount).toBe(1);

    finish?.();

    expect(await screen.findByText('Uploaded photo.jpg.')).toBeInTheDocument();
    expect(screen.queryByRole('progressbar')).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Upload' })).toHaveAttribute(
      'aria-disabled',
      'false',
    );
  });

  describe('when the server rejects the upload', () => {
    it("shows the server's validation messages at their fields and keeps the input", async () => {
      serveUpload(422, {
        message: 'The file must be between 500×500 and 10000×10000 pixels. (and 2 more errors)',
        errors: {
          file: ['The file must be between 500×500 and 10000×10000 pixels.'],
          uploader_email: ['The e-mail field must be a valid email address.', 'Second message.'],
          cursor: ['The cursor is invalid.'],
        },
      });
      const user = userEvent.setup();
      renderWithProviders(<UploadForm />);
      await fillIn(user);

      await submit(user);

      expect(
        await screen.findByText('The file must be between 500×500 and 10000×10000 pixels.'),
      ).toBeInTheDocument();
      expect(field('Your e-mail')).toHaveAccessibleDescription(
        'The e-mail field must be a valid email address.',
      );
      expect(screen.queryByText('Second message.')).not.toBeInTheDocument();
      // A message for no field of the form still reaches the user.
      expect(screen.getByRole('alert')).toHaveTextContent('The cursor is invalid.');
      // The first invalid field in form order.
      expect(field('Your e-mail')).toHaveFocus();
      expect(field('Your name')).toHaveValue('Jane Doe');
      expect(field('Image').files?.[0]?.name).toBe('photo.jpg');
    });

    it('clears a server message once the field is edited', async () => {
      serveUpload(422, {
        message: 'The e-mail field must be a valid email address.',
        errors: { uploader_email: ['The e-mail field must be a valid email address.'] },
      });
      const user = userEvent.setup();
      renderWithProviders(<UploadForm />);
      await fillIn(user);
      await submit(user);
      await screen.findByText('The e-mail field must be a valid email address.');

      await user.type(field('Your e-mail'), 'm');

      await waitFor(() => {
        expect(
          screen.queryByText('The e-mail field must be a valid email address.'),
        ).not.toBeInTheDocument();
      });
    });

    // nginx or PHP reject an oversized body before Laravel can validate it.
    it('reports a 413 at the file', async () => {
      serveUpload(413, { message: 'The POST data is too large.' });
      const user = userEvent.setup();
      renderWithProviders(<UploadForm />);
      await fillIn(user);

      await submit(user);

      expect(await screen.findByText('The file is too large for the server.')).toBeInTheDocument();
      expect(field('Image')).toHaveAttribute('aria-invalid', 'true');
    });

    it('reports a server error for the whole form', async () => {
      serveUpload(500, { message: 'Server Error' });
      const user = userEvent.setup();
      renderWithProviders(<UploadForm />);
      await fillIn(user);

      await submit(user);

      expect(await screen.findByRole('alert')).toHaveTextContent(
        'The upload failed. Please try again.',
      );
      expect(screen.queryByText('Uploaded photo.jpg.')).not.toBeInTheDocument();
    });

    it('reports a network failure', async () => {
      server.use(http.post(IMAGES_URL, () => HttpResponse.error()));
      const user = userEvent.setup();
      renderWithProviders(<UploadForm />);
      await fillIn(user);

      await submit(user);

      expect(await screen.findByRole('alert')).toHaveTextContent(
        'Could not reach the server. Check your connection and try again.',
      );
    });

    // E.g. a proxy mangled the 201, or the client timed out after the server stored the file.
    it('refreshes the list when the image may have been stored anyway', async () => {
      const stored = makeImagePayload({ original_name: 'photo.jpg' });
      let listed: ReturnType<typeof makeImagePayload>[] = [];
      server.use(
        http.get(IMAGES_URL, () => HttpResponse.json(makeImagePagePayload(listed))),
        http.post(IMAGES_URL, () => {
          listed = [stored];

          return HttpResponse.json({ data: { id: stored.id } }, { status: 201 });
        }),
      );
      const user = userEvent.setup();
      renderWithProviders(<App />);
      await screen.findByText('No images uploaded yet.');
      await fillIn(user);

      await submit(user);

      expect(await screen.findByRole('alert')).toHaveTextContent(
        'The image may have been uploaded anyway; check the list before trying again.',
      );
      expect(await screen.findByRole('article', { name: 'photo.jpg' })).toBeInTheDocument();
    });

    it('clears the form error on the next attempt', async () => {
      const requests: number[] = [];
      server.use(
        http.post(IMAGES_URL, () => {
          requests.push(requests.length);

          return requests.length === 1
            ? HttpResponse.json({ message: 'Server Error' }, { status: 500 })
            : HttpResponse.json({ data: makeImagePayload() }, { status: 201 });
        }),
      );
      const user = userEvent.setup();
      renderWithProviders(<UploadForm />);
      await fillIn(user);
      await submit(user);
      const alert = await screen.findByRole('alert');

      await submit(user);

      expect(await screen.findByText('Uploaded photo.jpg.')).toBeInTheDocument();
      expect(alert).not.toBeInTheDocument();
    });
  });
});
