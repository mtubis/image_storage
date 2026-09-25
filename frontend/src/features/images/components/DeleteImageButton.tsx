import { useRef, useState, type KeyboardEvent } from 'react';
import { flushSync } from 'react-dom';
import type { ApiImage } from '@/api/schemas';
import { useDeleteImage } from '@/features/images/hooks/useDeleteImage';
import styles from './ImageCard.module.css';

interface DeleteImageButtonProps {
  image: ApiImage;
  onDeleted: (image: ApiImage) => void;
}

// "Delete" turns into an inline confirmation instead of a modal: `window.confirm` can't be
// styled, and the card is already the context the question is about.
export function DeleteImageButton({ image, onDeleted }: DeleteImageButtonProps) {
  const [isConfirming, setIsConfirming] = useState(false);
  const { mutate, isPending, isError, reset } = useDeleteImage({ onDeleted });
  const deleteRef = useRef<HTMLButtonElement>(null);
  const cancelRef = useRef<HTMLButtonElement>(null);

  // The button that should get the focus only exists after the state change has rendered,
  // hence flushSync before focusing it.
  const showConfirmation = (show: boolean) => {
    flushSync(() => {
      setIsConfirming(show);
    });
    // The safe choice gets the focus, so an accidental Enter doesn't delete.
    (show ? cancelRef : deleteRef).current?.focus();
  };

  const confirm = () => {
    // aria-disabled, unlike `disabled`, doesn't block clicks.
    if (isPending) {
      return;
    }
    mutate(image, {
      onError: () => {
        showConfirmation(false);
      },
    });
  };

  const cancelOnEscape = (event: KeyboardEvent) => {
    if (event.key === 'Escape' && !isPending) {
      showConfirmation(false);
    }
  };

  return (
    <>
      {isConfirming ? (
        <div
          className={styles.confirmation}
          role="group"
          // The visible prompt leaves out the name, which can be 255 characters long; the
          // card's heading already shows it.
          aria-label={`Delete ${image.original_name}?`}
        >
          <p className={styles.prompt}>Delete this image?</p>
          {/* Kept mounted while pending, so the focus stays on it; `aria-disabled` rather than
              `disabled`, which would drop the focus to <body>. */}
          <button
            type="button"
            className={styles.danger}
            aria-disabled={isPending}
            onClick={confirm}
            onKeyDown={cancelOnEscape}
          >
            {isPending ? 'Deleting…' : 'Yes, delete'}
          </button>
          {/* A sent DELETE can't be taken back. */}
          {!isPending && (
            <button
              ref={cancelRef}
              type="button"
              className={styles.action}
              onClick={() => {
                showConfirmation(false);
              }}
              onKeyDown={cancelOnEscape}
            >
              Cancel
            </button>
          )}
        </div>
      ) : (
        <button
          ref={deleteRef}
          type="button"
          className={styles.danger}
          // Contains the visible text (WCAG 2.5.3) and tells the cards' buttons apart.
          aria-label={`Delete ${image.original_name}`}
          onClick={() => {
            reset();
            showConfirmation(true);
          }}
        >
          Delete
        </button>
      )}
      {isError && !isConfirming && (
        <p className={styles.error} role="alert">
          Could not delete {image.original_name}. Please try again.
        </p>
      )}
    </>
  );
}
