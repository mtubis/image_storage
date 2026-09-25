import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { delay, http, HttpResponse } from 'msw';
import { describe, expect, it } from 'vitest';
import { ImageList } from '@/features/images/components/ImageList';
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
import { imageKeys } from '@/features/images/queryKeys';

// Serves the listing by cursor (the first page has none) and records every requested cursor.
function servePages(pages: Record<string, ImagePagePayload>) {
  const requestedCursors: (string | null)[] = [];
  server.use(
    http.get(IMAGES_URL, ({ request }) => {
      const cursor = new URL(request.url).searchParams.get('cursor');
      requestedCursors.push(cursor);
      const page = pages[cursor ?? 'first'];
      if (page === undefined) {
        throw new Error(`Unexpected cursor ${String(cursor)}`);
      }

      return HttpResponse.json(page);
    }),
  );

  return requestedCursors;
}

type DeleteResponse = 204 | 404 | 500 | 'network-error';

// Answers every DELETE with the given response after a short delay (20 ms unless set per ID),
// so the pending state renders, and records the deleted IDs.
// A delay per image ID is either milliseconds or a promise that holds the response until the
// test settles it, for assertions that must run while the request is still pending.
function serveDelete(
  response: DeleteResponse = 204,
  delays: Record<string, number | Promise<void>> = {},
) {
  const deletedIds: string[] = [];
  server.use(
    http.delete(`${IMAGES_URL}/:id`, async ({ params }) => {
      const id = String(params.id);
      deletedIds.push(id);
      const wait = delays[id] ?? 20;
      await (typeof wait === 'number' ? delay(wait) : wait);
      if (response === 'network-error') {
        return HttpResponse.error();
      }
      if (response === 204) {
        return new HttpResponse(null, { status: 204 });
      }

      return HttpResponse.json(
        { message: response === 404 ? 'Not found.' : 'Server Error' },
        { status: response },
      );
    }),
  );

  return deletedIds;
}

// A deletion's announcement, which must sit in a live region to be read out.
function announcement(text: string) {
  const element = screen.getByText(text);
  expect(element.closest('[role="status"]')).not.toBeNull();

  return element;
}

function card(name: string) {
  return screen.getByRole('article', { name });
}

// A promise the test resolves when it chooses, to order events without relying on timing.
function gate(): { passed: Promise<void>; open: () => void } {
  let open = (): void => undefined;
  const passed = new Promise<void>((resolve) => {
    open = resolve;
  });

  return { passed, open };
}

async function settle() {
  await new Promise((resolve) => setTimeout(resolve, 50));
}

describe('ImageList download', () => {
  it('links every card to the download of its original file', async () => {
    const image = makeImagePayload({ original_name: 'holiday.tiff' });
    servePages({ first: makeImagePagePayload([image]) });

    renderWithProviders(<ImageList />);

    const link = await screen.findByRole('link', { name: 'Download holiday.tiff' });
    expect(link).toHaveAttribute('href', image.download_url);
    expect(link).toHaveTextContent('Download');
  });
});

describe('ImageList delete', () => {
  it('asks for confirmation before deleting', async () => {
    const user = userEvent.setup();
    servePages({ first: makeImagePagePayload([makeImagePayload({ original_name: 'a.jpg' })]) });
    const deleted = serveDelete();
    renderWithProviders(<ImageList />);

    await user.click(await screen.findByRole('button', { name: 'Delete a.jpg' }));

    const confirmation = within(card('a.jpg')).getByRole('group', { name: 'Delete a.jpg?' });
    expect(within(confirmation).getByRole('button', { name: 'Yes, delete' })).toBeInTheDocument();
    // The safe choice has the focus, so an accidental Enter doesn't delete.
    expect(within(confirmation).getByRole('button', { name: 'Cancel' })).toHaveFocus();
    await settle();
    expect(deleted).toEqual([]);
  });

  it('keeps the image when the deletion is cancelled', async () => {
    const user = userEvent.setup();
    servePages({ first: makeImagePagePayload([makeImagePayload({ original_name: 'a.jpg' })]) });
    const deleted = serveDelete();
    renderWithProviders(<ImageList />);

    await user.click(await screen.findByRole('button', { name: 'Delete a.jpg' }));
    await user.click(screen.getByRole('button', { name: 'Cancel' }));

    expect(screen.queryByRole('group', { name: 'Delete a.jpg?' })).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Delete a.jpg' })).toHaveFocus();
    await settle();
    expect(deleted).toEqual([]);
    expect(card('a.jpg')).toBeInTheDocument();
  });

  it('cancels with Escape', async () => {
    const user = userEvent.setup();
    servePages({ first: makeImagePagePayload([makeImagePayload({ original_name: 'a.jpg' })]) });
    const deleted = serveDelete();
    renderWithProviders(<ImageList />);

    await user.click(await screen.findByRole('button', { name: 'Delete a.jpg' }));
    await user.keyboard('{Escape}');

    expect(screen.queryByRole('group', { name: 'Delete a.jpg?' })).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Delete a.jpg' })).toHaveFocus();
    await settle();
    expect(deleted).toEqual([]);
  });

  it('deletes a confirmed image and removes it without reloading the list', async () => {
    const user = userEvent.setup();
    const [first, second, third] = [
      makeImagePayload({ original_name: 'a.jpg' }),
      makeImagePayload({ original_name: 'b.jpg' }),
      makeImagePayload({ original_name: 'c.jpg' }),
    ];
    const requested = servePages({ first: makeImagePagePayload([first, second, third]) });
    // Held until the pending state has been checked: a response landing in the middle of the
    // click on the pending button raced with the focus assertion (flaky on slow CI runners).
    let finishDelete: (() => void) | undefined;
    const deleted = serveDelete(204, {
      [second.id]: new Promise<void>((resolve) => {
        finishDelete = resolve;
      }),
    });
    renderWithProviders(<ImageList />);

    await user.click(await screen.findByRole('button', { name: 'Delete b.jpg' }));
    await user.click(screen.getByRole('button', { name: 'Yes, delete' }));

    // Pending: the clicked button stays (with the focus) but can't send a second request.
    const pending = within(card('b.jpg')).getByRole('button', { name: 'Deleting…' });
    expect(pending).toHaveAttribute('aria-disabled', 'true');
    expect(pending).toHaveFocus();
    expect(within(card('b.jpg')).queryByRole('button', { name: 'Cancel' })).toBeNull();
    await user.click(pending);
    finishDelete?.();

    await waitFor(() => {
      expect(screen.queryByRole('article', { name: 'b.jpg' })).not.toBeInTheDocument();
    });
    expect(deleted).toEqual([second.id]);
    expect(
      screen.getAllByRole('heading', { level: 3 }).map((heading) => heading.textContent),
    ).toEqual(['a.jpg', 'c.jpg']);
    announcement('Deleted b.jpg.');
    // The focus moves to the card that took the deleted one's place.
    expect(within(card('c.jpg')).getByRole('heading', { name: 'c.jpg' })).toHaveFocus();
    await settle();
    expect(requested).toEqual([null]);
  });

  it('moves the focus to the previous card after deleting the last one', async () => {
    const user = userEvent.setup();
    servePages({
      first: makeImagePagePayload([
        makeImagePayload({ original_name: 'a.jpg' }),
        makeImagePayload({ original_name: 'b.jpg' }),
      ]),
    });
    serveDelete();
    renderWithProviders(<ImageList />);

    await user.click(await screen.findByRole('button', { name: 'Delete b.jpg' }));
    await user.click(screen.getByRole('button', { name: 'Yes, delete' }));

    await waitFor(() => {
      expect(within(card('a.jpg')).getByRole('heading', { name: 'a.jpg' })).toHaveFocus();
    });
  });

  it('shows the empty state after deleting the only image', async () => {
    const user = userEvent.setup();
    servePages({ first: makeImagePagePayload([makeImagePayload({ original_name: 'a.jpg' })]) });
    serveDelete();
    renderWithProviders(<ImageList />);

    await user.click(await screen.findByRole('button', { name: 'Delete a.jpg' }));
    await user.click(screen.getByRole('button', { name: 'Yes, delete' }));

    await waitFor(() => {
      expect(screen.queryByRole('article')).not.toBeInTheDocument();
    });
    announcement('Deleted a.jpg.');
    expect(screen.getByText('No images uploaded yet.')).toBeInTheDocument();
    expect(screen.getByRole('heading', { level: 2, name: 'Uploaded images' })).toHaveFocus();
  });

  it('loads the next page instead of claiming the list is empty', async () => {
    const user = userEvent.setup();
    const observer = stubIntersectionObserver();
    const requested = servePages({
      first: makeImagePagePayload([makeImagePayload({ original_name: 'a.jpg' })], 'page-2'),
      'page-2': makeImagePagePayload([makeImagePayload({ original_name: 'b.jpg' })]),
    });
    serveDelete();
    renderWithProviders(<ImageList />);

    await user.click(await screen.findByRole('button', { name: 'Delete a.jpg' }));
    await user.click(screen.getByRole('button', { name: 'Yes, delete' }));
    await waitFor(() => {
      expect(screen.queryByRole('article', { name: 'a.jpg' })).not.toBeInTheDocument();
    });

    expect(screen.queryByText('No images uploaded yet.')).not.toBeInTheDocument();
    observer.setIntersecting(true);
    expect(await screen.findByRole('article', { name: 'b.jpg' })).toBeInTheDocument();
    expect(requested).toEqual([null, 'page-2']);
  });

  it('deletes an image from a later page and keeps the others', async () => {
    const user = userEvent.setup();
    const firstPage = makeImagePayloads(10);
    const secondPage = [
      makeImagePayload({ original_name: 'x.jpg' }),
      makeImagePayload({ original_name: 'y.jpg' }),
    ];
    servePages({
      first: makeImagePagePayload(firstPage, 'page-2'),
      'page-2': makeImagePagePayload(secondPage),
    });
    const deleted = serveDelete();
    renderWithProviders(<ImageList />);
    expect(await screen.findAllByRole('article')).toHaveLength(10);
    await user.click(screen.getByRole('button', { name: 'Load more' }));
    expect(await screen.findAllByRole('article')).toHaveLength(12);

    await user.click(screen.getByRole('button', { name: 'Delete x.jpg' }));
    await user.click(screen.getByRole('button', { name: 'Yes, delete' }));

    await waitFor(() => {
      expect(screen.getAllByRole('article')).toHaveLength(11);
    });
    expect(deleted).toEqual([secondPage[0]?.id]);
    expect(card('y.jpg')).toBeInTheDocument();
  });

  it('removes an image that was already deleted elsewhere', async () => {
    const user = userEvent.setup();
    servePages({ first: makeImagePagePayload([makeImagePayload({ original_name: 'a.jpg' })]) });
    serveDelete(404);
    renderWithProviders(<ImageList />);

    await user.click(await screen.findByRole('button', { name: 'Delete a.jpg' }));
    await user.click(screen.getByRole('button', { name: 'Yes, delete' }));

    await waitFor(() => {
      expect(screen.queryByRole('article', { name: 'a.jpg' })).not.toBeInTheDocument();
    });
    expect(screen.queryByRole('alert')).not.toBeInTheDocument();
  });

  it.each([
    ['a server error', 500],
    ['a network error', 'network-error'],
  ] as const)('keeps the image and shows an error after %s', async (_case, response) => {
    const user = userEvent.setup();
    servePages({ first: makeImagePagePayload([makeImagePayload({ original_name: 'a.jpg' })]) });
    serveDelete(response);
    renderWithProviders(<ImageList />);

    await user.click(await screen.findByRole('button', { name: 'Delete a.jpg' }));
    await user.click(screen.getByRole('button', { name: 'Yes, delete' }));

    expect(await within(card('a.jpg')).findByRole('alert')).toHaveTextContent(
      'Could not delete a.jpg. Please try again.',
    );
    expect(screen.getByRole('button', { name: 'Delete a.jpg' })).toHaveFocus();
    expect(screen.queryByRole('group', { name: 'Delete a.jpg?' })).not.toBeInTheDocument();
  });

  it('clears the error when the deletion is tried again', async () => {
    const user = userEvent.setup();
    servePages({ first: makeImagePagePayload([makeImagePayload({ original_name: 'a.jpg' })]) });
    serveDelete(500);
    renderWithProviders(<ImageList />);
    await user.click(await screen.findByRole('button', { name: 'Delete a.jpg' }));
    await user.click(screen.getByRole('button', { name: 'Yes, delete' }));
    await within(card('a.jpg')).findByRole('alert');

    serveDelete(204);
    await user.click(screen.getByRole('button', { name: 'Delete a.jpg' }));

    expect(screen.queryByRole('alert')).not.toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Yes, delete' }));
    await waitFor(() => {
      expect(screen.queryByRole('article', { name: 'a.jpg' })).not.toBeInTheDocument();
    });
  });

  it('keeps a deleted image out when a page that was loading arrives later', async () => {
    const user = userEvent.setup();
    const observer = stubIntersectionObserver();
    const firstPage = [makeImagePayload({ original_name: 'a.jpg' }), ...makeImagePayloads(9)];
    const secondPage = [makeImagePayload({ original_name: 'x.jpg' })];
    const nextPageRequested = gate();
    const nextPageAnswered = gate();
    server.use(
      // The first answer still contains the image; later ones come after the deletion.
      http.get(IMAGES_URL, () => HttpResponse.json(makeImagePagePayload(firstPage, 'page-2')), {
        once: true,
      }),
      http.get(IMAGES_URL, async ({ request }) => {
        if (new URL(request.url).searchParams.get('cursor') === 'page-2') {
          // Held until the deletion has finished, so it lands after it.
          nextPageRequested.open();
          await nextPageAnswered.passed;

          return HttpResponse.json(makeImagePagePayload(secondPage));
        }

        return HttpResponse.json(makeImagePagePayload(firstPage.slice(1), 'page-2'));
      }),
    );
    serveDelete();
    renderWithProviders(<ImageList />);
    expect(await screen.findAllByRole('article')).toHaveLength(10);

    observer.setIntersecting(true);
    await nextPageRequested.passed;
    await user.click(screen.getByRole('button', { name: 'Delete a.jpg' }));
    await user.click(screen.getByRole('button', { name: 'Yes, delete' }));
    await waitFor(() => {
      expect(screen.queryByRole('article', { name: 'a.jpg' })).not.toBeInTheDocument();
    });
    nextPageAnswered.open();

    expect(await screen.findByRole('article', { name: 'x.jpg' })).toBeInTheDocument();
    await settle();
    expect(screen.queryByRole('article', { name: 'a.jpg' })).not.toBeInTheDocument();
    expect(screen.getAllByRole('article')).toHaveLength(10);
  });

  it('keeps a deleted image out and still shows a new upload when a refetch was running', async () => {
    const user = userEvent.setup();
    const [uploaded, first, second] = [
      makeImagePayload({ original_name: 'new.jpg' }),
      makeImagePayload({ original_name: 'a.jpg' }),
      makeImagePayload({ original_name: 'b.jpg' }),
    ];
    // Initial load, then a refetch from before the deletion (held until the deletion has
    // finished, so it lands after it), then any later refetch.
    const staleRefetchRequested = gate();
    const staleRefetchAnswered = gate();
    const answers = [
      { images: [first, second], held: false },
      { images: [uploaded, first, second], held: true },
    ];
    server.use(
      http.get(IMAGES_URL, async () => {
        const answer = answers.shift() ?? { images: [uploaded, second], held: false };
        if (answer.held) {
          staleRefetchRequested.open();
          await staleRefetchAnswered.passed;
        }

        return HttpResponse.json(makeImagePagePayload(answer.images));
      }),
    );
    serveDelete();
    const { queryClient } = renderWithProviders(<ImageList />);
    expect(await screen.findAllByRole('article')).toHaveLength(2);

    await user.click(screen.getByRole('button', { name: 'Delete a.jpg' }));
    // What an upload does when it settles.
    void queryClient.invalidateQueries({ queryKey: imageKeys.list() });
    await staleRefetchRequested.passed;
    await user.click(screen.getByRole('button', { name: 'Yes, delete' }));
    await waitFor(() => {
      expect(screen.queryByRole('article', { name: 'a.jpg' })).not.toBeInTheDocument();
    });
    staleRefetchAnswered.open();

    expect(await screen.findByRole('article', { name: 'new.jpg' })).toBeInTheDocument();
    await settle();
    expect(
      screen.getAllByRole('heading', { level: 3 }).map((heading) => heading.textContent),
    ).toEqual(['new.jpg', 'b.jpg']);
  });

  it('leaves the focus alone when it is no longer in the deleted card', async () => {
    const user = userEvent.setup();
    const [first, second] = [
      makeImagePayload({ original_name: 'a.jpg' }),
      makeImagePayload({ original_name: 'b.jpg' }),
    ];
    servePages({ first: makeImagePagePayload([first, second]) });
    serveDelete(204, { [first.id]: 100 });
    renderWithProviders(<ImageList />);

    await user.click(await screen.findByRole('button', { name: 'Delete a.jpg' }));
    await user.click(screen.getByRole('button', { name: 'Yes, delete' }));
    await user.click(screen.getByRole('button', { name: 'Delete b.jpg' }));
    const cancel = within(card('b.jpg')).getByRole('button', { name: 'Cancel' });
    expect(cancel).toHaveFocus();

    await waitFor(() => {
      expect(screen.queryByRole('article', { name: 'a.jpg' })).not.toBeInTheDocument();
    });
    expect(cancel).toHaveFocus();
    announcement('Deleted a.jpg.');
  });

  it('announces each deletion, also of files with the same name', async () => {
    const user = userEvent.setup();
    servePages({
      first: makeImagePagePayload([
        makeImagePayload({ original_name: 'IMG_0001.jpg' }),
        makeImagePayload({ original_name: 'IMG_0001.jpg' }),
      ]),
    });
    serveDelete();
    renderWithProviders(<ImageList />);

    const deleteFirst = async () => {
      const [button] = await screen.findAllByRole('button', { name: 'Delete IMG_0001.jpg' });
      if (button === undefined) {
        throw new Error('No delete button');
      }
      await user.click(button);
      await user.click(screen.getByRole('button', { name: 'Yes, delete' }));
    };
    await deleteFirst();
    await waitFor(() => {
      expect(screen.getAllByRole('article')).toHaveLength(1);
    });
    const firstAnnouncement = announcement('Deleted IMG_0001.jpg.');
    await deleteFirst();
    await waitFor(() => {
      expect(screen.queryByRole('article')).not.toBeInTheDocument();
    });

    // Screen readers announce content added to a live region; unchanged text is not read.
    const secondAnnouncement = announcement('Deleted IMG_0001.jpg.');
    expect(secondAnnouncement).not.toBe(firstAnnouncement);
    expect(firstAnnouncement).not.toBeInTheDocument();
  });
});
