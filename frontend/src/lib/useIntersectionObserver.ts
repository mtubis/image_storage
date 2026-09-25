import { useEffect, useState } from 'react';

interface Options {
  rootMargin?: string;
}

interface IntersectionState {
  // Callback ref: attach it to the element to observe.
  ref: (element: Element | null) => void;
  isIntersecting: boolean;
}

// Reports whether an element is in (or within `rootMargin` of) the viewport. Where the API is
// missing it never reports an intersection, so callers need a non-scroll fallback.
export function useIntersectionObserver({ rootMargin = '0px' }: Options = {}): IntersectionState {
  // State rather than a ref object, so attaching a different element re-subscribes.
  const [element, setElement] = useState<Element | null>(null);
  const [isIntersecting, setIsIntersecting] = useState(false);

  useEffect(() => {
    if (element === null || !('IntersectionObserver' in window)) {
      return;
    }

    const observer = new IntersectionObserver(
      (entries) => {
        const latest = entries.at(-1);
        if (latest !== undefined) {
          setIsIntersecting(latest.isIntersecting);
        }
      },
      { rootMargin },
    );
    observer.observe(element);

    return () => {
      observer.disconnect();
      // A detached element can't be in view, and the next one reports its own state.
      setIsIntersecting(false);
    };
  }, [element, rootMargin]);

  return { ref: setElement, isIntersecting };
}
