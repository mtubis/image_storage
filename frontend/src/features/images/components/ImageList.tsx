import { useEffect, useId, useRef, useState, type Ref } from 'react';
import type { ApiImage } from '@/api/schemas';
import { ImageCard } from '@/features/images/components/ImageCard';
import { useImagesInfinite } from '@/features/images/hooks/useImagesInfinite';
import { useIntersectionObserver } from '@/lib/useIntersectionObserver';
import styles from './ImageList.module.css';

// Start loading the next page a bit before the user reaches the end of the list.
const PRELOAD_MARGIN = '400px';

export function ImageList() {
  const headingId = useId();
  const headingRef = useRef<HTMLHeadingElement>(null);
  const gridRef = useRef<HTMLUListElement>(null);
  // Keyed by a counter: deleting two files with the same name must be announced twice.
  const [deletion, setDeletion] = useState<{ key: number; text: string } | null>(null);
  const {
    data: images,
    dataUpdatedAt,
    isError,
    refetch,
    fetchNextPage,
    hasNextPage,
    isFetching,
    isFetchingNextPage,
    isFetchNextPageError,
  } = useImagesInfinite();

  // After deleting every loaded image, more may still be on the server.
  const isEmpty = images?.length === 0 && !hasNextPage;
  let status = '';
  if (images === undefined && !isError) {
    status = 'Loading images…';
  } else if (isEmpty) {
    status = 'No images uploaded yet.';
  }

  // Runs while the deleted card is still rendered, so its neighbours can be found in the DOM.
  // If the focus is in the card, it would drop to <body> with it: it goes to the card that
  // takes its place, the previous one after the last card, or the list's heading. Anywhere
  // else (e.g. another card's confirmation), it stays where the user put it.
  const handleDeleted = (image: ApiImage) => {
    const item = gridRef.current?.querySelector(`[data-image-id="${image.id}"]`);
    if (item?.contains(document.activeElement) === true) {
      const neighbour = item.nextElementSibling ?? item.previousElementSibling;
      const target = neighbour?.querySelector<HTMLElement>('h3') ?? headingRef.current;
      target?.focus();
    }
    setDeletion((previous) => ({
      key: (previous?.key ?? 0) + 1,
      text: `Deleted ${image.original_name}.`,
    }));
  };

  return (
    <section aria-labelledby={headingId}>
      {/* Focusable from script only, as the fallback focus target after a deletion. */}
      <h2 id={headingId} ref={headingRef} className={styles.heading} tabIndex={-1}>
        Uploaded images
      </h2>
      {/* Always rendered: screen readers only announce changes of an existing live region. */}
      <p className={styles.message} role="status">
        {status}
      </p>
      {/* Screen readers only: sighted users see the card go. A new node per deletion, since
          only content added to a live region is announced. */}
      <p className={styles.visuallyHidden} role="status">
        {deletion !== null && <span key={deletion.key}>{deletion.text}</span>}
      </p>
      {images === undefined && isError && (
        <div className={styles.message}>
          <p role="alert">Could not load images.</p>
          {/* Replaced by the loading state as soon as the retry starts. */}
          <button type="button" onClick={() => void refetch()}>
            Try again
          </button>
        </div>
      )}
      {images !== undefined && !isEmpty && (
        <>
          {/* Empty while the loaded images are all deleted but more are on the server. */}
          {images.length > 0 && (
            <ImageGrid
              ref={gridRef}
              images={images}
              isBusy={isFetchingNextPage}
              onDeleted={handleDeleted}
            />
          )}
          <ListEnd
            dataUpdatedAt={dataUpdatedAt}
            hasNextPage={hasNextPage}
            isFetching={isFetching}
            isFetchingNextPage={isFetchingNextPage}
            isFetchNextPageError={isFetchNextPageError}
            // Never cancels a running fetch (a background refetch or the page itself); while one
            // runs, a call is a no-op.
            fetchNextPage={() => fetchNextPage({ cancelRefetch: false })}
          />
        </>
      )}
    </section>
  );
}

interface ImageGridProps {
  ref: Ref<HTMLUListElement>;
  images: ApiImage[];
  isBusy: boolean;
  onDeleted: (image: ApiImage) => void;
}

function ImageGrid({ ref, images, isBusy, onDeleted }: ImageGridProps) {
  return (
    <ul ref={ref} className={styles.grid} aria-busy={isBusy}>
      {images.map((image) => (
        <li key={image.id} data-image-id={image.id}>
          <ImageCard image={image} onDeleted={onDeleted} />
        </li>
      ))}
    </ul>
  );
}

interface ListEndProps {
  dataUpdatedAt: number;
  hasNextPage: boolean;
  isFetching: boolean;
  isFetchingNextPage: boolean;
  isFetchNextPageError: boolean;
  fetchNextPage: () => Promise<unknown>;
}

// The sentinel below the grid: loads the next page when it comes into view, with a button for
// keyboard users and browsers without IntersectionObserver.
function ListEnd({
  dataUpdatedAt,
  hasNextPage,
  isFetching,
  isFetchingNextPage,
  isFetchNextPageError,
  fetchNextPage,
}: ListEndProps) {
  const { ref, isIntersecting } = useIntersectionObserver({ rootMargin: PRELOAD_MARGIN });
  // Not after a failed page, or the effect would retry it in a loop; the user decides.
  const shouldLoadMore = isIntersecting && hasNextPage && !isFetchNextPageError;
  // Held in a ref: a new function every render must not re-run the effect.
  const fetchNextPageRef = useRef(fetchNextPage);
  useEffect(() => {
    fetchNextPageRef.current = fetchNextPage;
  });

  // The observer only fires when visibility changes. If the sentinel is still in view after a
  // page arrived (tall screen), the new `dataUpdatedAt` re-runs this until the viewport is
  // filled. `isFetching` alone can't be relied on: TanStack Query batches its notifications,
  // so a render may never see it flip. A call during a running fetch is a no-op
  // (`cancelRefetch: false`), and the data update of that fetch runs this again.
  useEffect(() => {
    if (shouldLoadMore) {
      void fetchNextPageRef.current();
    }
  }, [shouldLoadMore, dataUpdatedAt]);

  let status = '';
  if (isFetchingNextPage) {
    status = 'Loading more images…';
  } else if (!hasNextPage) {
    status = 'All images loaded.';
  }

  return (
    <div ref={ref} className={styles.end}>
      {isFetchNextPageError && (
        <p className={styles.error} role="alert">
          Could not load more images.
        </p>
      )}
      {/* One button for loading and retrying, kept mounted while loading so keyboard focus
          stays on it. `aria-disabled`, not `disabled`, which would drop the focus; a click
          meanwhile is a no-op anyway (`cancelRefetch: false`). */}
      {hasNextPage && (
        <button type="button" aria-disabled={isFetching} onClick={() => void fetchNextPage()}>
          {isFetchNextPageError ? 'Try again' : 'Load more'}
        </button>
      )}
      <p className={styles.status} role="status">
        {status}
      </p>
    </div>
  );
}
