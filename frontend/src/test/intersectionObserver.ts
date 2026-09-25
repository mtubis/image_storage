import { act } from '@testing-library/react';
import { vi } from 'vitest';

export interface ObserverStub {
  readonly options: IntersectionObserverInit | undefined;
  readonly targets: ReadonlySet<Element>;
}

export interface IntersectionObserverStub {
  // Observers that currently observe at least one element.
  readonly active: () => ObserverStub[];
  // Reports every observed element as entering (true) or leaving (false) the viewport.
  readonly setIntersecting: (isIntersecting: boolean) => void;
}

// jsdom has no IntersectionObserver; this stand-in lets tests decide when the sentinel is
// "visible". Vitest's `unstubGlobals` removes it after each test.
export function stubIntersectionObserver(): IntersectionObserverStub {
  const active = new Set<FakeIntersectionObserver>();

  class FakeIntersectionObserver implements ObserverStub {
    readonly targets = new Set<Element>();
    readonly options: IntersectionObserverInit | undefined;
    readonly #callback: IntersectionObserverCallback;

    constructor(callback: IntersectionObserverCallback, options?: IntersectionObserverInit) {
      this.#callback = callback;
      this.options = options;
    }

    observe(target: Element): void {
      this.targets.add(target);
      active.add(this);
    }

    unobserve(target: Element): void {
      this.targets.delete(target);
      if (this.targets.size === 0) {
        active.delete(this);
      }
    }

    disconnect(): void {
      this.targets.clear();
      active.delete(this);
    }

    takeRecords(): IntersectionObserverEntry[] {
      return [];
    }

    notify(isIntersecting: boolean): void {
      const entries = [...this.targets].map((target): IntersectionObserverEntry => ({
        target,
        isIntersecting,
        intersectionRatio: isIntersecting ? 1 : 0,
        boundingClientRect: target.getBoundingClientRect(),
        intersectionRect: target.getBoundingClientRect(),
        rootBounds: null,
        time: performance.now(),
      }));
      this.#callback(entries, this as unknown as IntersectionObserver);
    }
  }

  vi.stubGlobal('IntersectionObserver', FakeIntersectionObserver);

  return {
    active: () => [...active],
    setIntersecting: (isIntersecting) => {
      act(() => {
        for (const observer of active) {
          observer.notify(isIntersecting);
        }
      });
    },
  };
}
