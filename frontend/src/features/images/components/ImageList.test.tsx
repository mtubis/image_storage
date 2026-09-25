import { act, fireEvent, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { delay, http, HttpResponse } from 'msw';
import { describe, expect, it } from 'vitest';
import { ImageList } from '@/features/images/components/ImageList';
import { imageKeys } from '@/features/images/queryKeys';
import {
  makeImagePagePayload,
  makeImagePayload,
  makeImagePayloads,
  type ImagePagePayload,
} from '@/test/factories';
import { stubIntersectionObserver } from '@/test/intersectionObserver';
import { IMAGES_URL } from '@/test/msw/handlers';
import { server } from '@/test/msw/server';
import { renderWithProviders } from '@/test/render';

type PageResponse = ImagePagePayload | 'error';

// Serves the listing by cursor (the first page has none) and records every requested cursor.
// 'error' answers 500; a pair answers with its first entry once and with the second afterwards.
function servePages(pages: Record<string, PageResponse | [PageResponse, PageResponse]>) {
  const requestedCursors: (string | null)[] = [];
  const answered = new Map<string, number>();

  server.use(
    http.get(IMAGES_URL, async ({ request }) => {
      const cursor = new URL(request.url).searchParams.get('cursor');
      requestedCursors.push(cursor);
      // Like a real network: the component renders the fetching state before the answer.
      await delay(20);
      const key = cursor ?? 'first';
      const attempt = answered.get(key) ?? 0;
      answered.set(key, attempt + 1);

      const configured = pages[key];
      const response = Array.isArray(configured) ? configured[Math.min(attempt, 1)] : configured;
      if (response === undefined) {
        throw new Error(`Unexpected cursor ${key}`);
      }
      if (response === 'error') {
        return HttpResponse.json({ message: 'Server Error' }, { status: 500 });
      }

      return HttpResponse.json(response);
    }),
  );

  return requestedCursors;
}

function cards() {
  return screen.queryAllByRole('article');
}

// Effects run inside act(), so a request they trigger reaches MSW within a few ticks. This only
// proves that no such request was sent; nothing in the component sends one later.
async function settle() {
  await new Promise((resolve) => setTimeout(resolve, 50));
}

describe('ImageList', () => {
  it('shows a loading state until the first page arrives', async () => {
    servePages({ first: makeImagePagePayload(makeImagePayloads(1)) });

    renderWithProviders(<ImageList />);

    expect(screen.getByRole('status')).toHaveTextContent('Loading images…');
    expect(screen.getByRole('heading', { level: 2, name: 'Uploaded images' })).toBeInTheDocument();
    expect(await screen.findAllByRole('article')).toHaveLength(1);
  });

  it('renders the first page with every listed detail', async () => {
    const image = makeImagePayload({
      original_name: 'holiday.png',
      extension: 'png',
      size_bytes: 1536,
      width: 1920,
      height: 1080,
      uploader_name: 'Anna Kowalska',
    });
    servePages({ first: makeImagePagePayload([image, ...makeImagePayloads(9)], 'page-2') });

    renderWithProviders(<ImageList />);

    expect(await screen.findAllByRole('article')).toHaveLength(10);
    const card = screen.getByRole('article', { name: 'holiday.png' });
    const thumbnail = within(card).getByRole('img', { name: 'Thumbnail of holiday.png' });
    expect(thumbnail).toHaveAttribute('src', image.thumbnail_url);
    expect(thumbnail).toHaveAttribute('loading', 'lazy');
    expect(within(card).getByText('PNG')).toBeInTheDocument();
    expect(within(card).getByText('1.5 KiB')).toBeInTheDocument();
    expect(within(card).getByText('1920 × 1080 px')).toBeInTheDocument();
    expect(within(card).getByText('Anna Kowalska')).toBeInTheDocument();
  });

  it('keeps the order of the API (newest first)', async () => {
    const images = makeImagePayloads(3);
    servePages({ first: makeImagePagePayload(images) });

    renderWithProviders(<ImageList />);

    await screen.findAllByRole('article');
    expect(screen.getAllByRole('img').map((thumbnail) => thumbnail.getAttribute('src'))).toEqual(
      images.map((image) => image.thumbnail_url),
    );
  });

  it('loads the next page when the end of the list scrolls into view', async () => {
    const observer = stubIntersectionObserver();
    const requested = servePages({
      first: makeImagePagePayload(makeImagePayloads(10), 'page-2'),
      'page-2': makeImagePagePayload(makeImagePayloads(2)),
    });
    renderWithProviders(<ImageList />);
    expect(await screen.findAllByRole('article')).toHaveLength(10);
    await settle();
    expect(requested).toEqual([null]);

    observer.setIntersecting(true);

    expect(await screen.findByText('All images loaded.')).toBeInTheDocument();
    expect(cards()).toHaveLength(12);
    expect(requested).toEqual([null, 'page-2']);
  });

  it('keeps loading while the end of the list stays in view', async () => {
    const observer = stubIntersectionObserver();
    const requested = servePages({
      first: makeImagePagePayload(makeImagePayloads(10), 'page-2'),
      'page-2': makeImagePagePayload(makeImagePayloads(10), 'page-3'),
      'page-3': makeImagePagePayload(makeImagePayloads(1)),
    });
    renderWithProviders(<ImageList />);
    await screen.findAllByRole('article');

    // One notification only: the observer stays silent while the visibility doesn't change.
    observer.setIntersecting(true);

    expect(await screen.findByText('All images loaded.')).toBeInTheDocument();
    expect(cards()).toHaveLength(21);
    expect(requested).toEqual([null, 'page-2', 'page-3']);
  });

  it('does not request more after the last page', async () => {
    const observer = stubIntersectionObserver();
    const requested = servePages({ first: makeImagePagePayload(makeImagePayloads(3)) });
    renderWithProviders(<ImageList />);
    expect(await screen.findByText('All images loaded.')).toBeInTheDocument();

    observer.setIntersecting(true);
    await settle();

    expect(requested).toEqual([null]);
    expect(screen.queryByRole('button', { name: 'Load more' })).not.toBeInTheDocument();
  });

  it('waits for a background refetch before loading the next page', async () => {
    const observer = stubIntersectionObserver();
    let finishRefetch: (() => void) | undefined;
    let refetchAborted: boolean | undefined;
    const requested: (string | null)[] = [];
    server.use(
      http.get(IMAGES_URL, async ({ request }) => {
        const cursor = new URL(request.url).searchParams.get('cursor');
        requested.push(cursor);
        if (cursor !== null) {
          return HttpResponse.json(makeImagePagePayload(makeImagePayloads(1)));
        }
        if (requested.length > 1) {
          await new Promise<void>((resolve) => {
            finishRefetch = resolve;
          });
          refetchAborted = request.signal.aborted;
        }

        return HttpResponse.json(makeImagePagePayload(makeImagePayloads(10), 'page-2'));
      }),
    );
    const { queryClient } = renderWithProviders(<ImageList />);
    await screen.findAllByRole('article');

    // E.g. after an upload (3.3); not awaited, the refetch is held until finishRefetch().
    act(() => {
      void queryClient.invalidateQueries({ queryKey: imageKeys.list() });
    });
    observer.setIntersecting(true);
    await settle();

    expect(requested).toEqual([null, null]);
    const loadMore = screen.getByRole('button', { name: 'Load more' });
    expect(loadMore).toHaveAttribute('aria-disabled', 'true');
    await userEvent.click(loadMore);
    expect(requested).toEqual([null, null]);

    // Also proves the refetch reached the server and is being held.
    expect(finishRefetch).toBeDefined();
    act(() => {
      finishRefetch?.();
    });

    expect(await screen.findByText('All images loaded.')).toBeInTheDocument();
    expect(requested).toEqual([null, null, 'page-2']);
    expect(refetchAborted).toBe(false);
  });

  it('keeps focus on the button while the next page loads', async () => {
    servePages({
      first: makeImagePagePayload(makeImagePayloads(10), 'page-2'),
      'page-2': makeImagePagePayload(makeImagePayloads(10), 'page-3'),
      'page-3': makeImagePagePayload(makeImagePayloads(1)),
    });
    renderWithProviders(<ImageList />);
    const loadMore = await screen.findByRole('button', { name: 'Load more' });

    loadMore.focus();
    await userEvent.keyboard('{Enter}');

    await waitFor(() => {
      expect(cards()).toHaveLength(20);
    });
    expect(screen.getByRole('button', { name: 'Load more' })).toHaveFocus();
  });

  it('loads the next page with the button where scrolling is not observed', async () => {
    const requested = servePages({
      first: makeImagePagePayload(makeImagePayloads(10), 'page-2'),
      'page-2': makeImagePagePayload(makeImagePayloads(1)),
    });
    renderWithProviders(<ImageList />);

    await userEvent.click(await screen.findByRole('button', { name: 'Load more' }));

    expect(await screen.findByText('All images loaded.')).toBeInTheDocument();
    expect(cards()).toHaveLength(11);
    expect(requested).toEqual([null, 'page-2']);
  });

  it('shows an empty state when nothing has been uploaded', async () => {
    servePages({ first: makeImagePagePayload([]) });

    renderWithProviders(<ImageList />);

    expect(await screen.findByText('No images uploaded yet.')).toBeInTheDocument();
    expect(screen.queryByText('All images loaded.')).not.toBeInTheDocument();
  });

  it('shows an error when the first page fails and recovers on retry', async () => {
    servePages({ first: ['error', makeImagePagePayload(makeImagePayloads(2))] });
    renderWithProviders(<ImageList />);

    expect(await screen.findByRole('alert')).toHaveTextContent('Could not load images.');
    expect(cards()).toHaveLength(0);

    await userEvent.click(screen.getByRole('button', { name: 'Try again' }));

    expect(await screen.findAllByRole('article')).toHaveLength(2);
    expect(screen.queryByRole('alert')).not.toBeInTheDocument();
  });

  it('keeps the loaded images when the next page fails, without retrying on its own', async () => {
    const observer = stubIntersectionObserver();
    const requested = servePages({
      first: makeImagePagePayload(makeImagePayloads(10), 'page-2'),
      'page-2': ['error', makeImagePagePayload(makeImagePayloads(3))],
    });
    renderWithProviders(<ImageList />);
    await screen.findAllByRole('article');

    observer.setIntersecting(true);

    expect(await screen.findByRole('alert')).toHaveTextContent('Could not load more images.');
    expect(cards()).toHaveLength(10);
    await settle();
    expect(requested).toEqual([null, 'page-2']);

    await userEvent.click(screen.getByRole('button', { name: 'Try again' }));

    expect(await screen.findByText('All images loaded.')).toBeInTheDocument();
    expect(cards()).toHaveLength(13);
    expect(requested).toEqual([null, 'page-2', 'page-2']);
  });

  it('shows a placeholder when a thumbnail cannot be loaded', async () => {
    servePages({ first: makeImagePagePayload([makeImagePayload({ original_name: 'scan.tif' })]) });
    renderWithProviders(<ImageList />);
    const card = await screen.findByRole('article', { name: 'scan.tif' });

    fireEvent.error(within(card).getByRole('img', { name: 'Thumbnail of scan.tif' }));

    expect(within(card).getByRole('img', { name: 'No preview of scan.tif' })).toHaveTextContent(
      'Preview unavailable',
    );
  });
});
