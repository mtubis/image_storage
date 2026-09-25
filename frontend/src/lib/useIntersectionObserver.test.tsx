import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { useIntersectionObserver } from '@/lib/useIntersectionObserver';
import { stubIntersectionObserver } from '@/test/intersectionObserver';

function Sentinel() {
  const { ref, isIntersecting } = useIntersectionObserver({ rootMargin: '200px' });

  return <div ref={ref}>{isIntersecting ? 'visible' : 'hidden'}</div>;
}

describe('useIntersectionObserver', () => {
  it('observes the element with the given root margin', () => {
    const observer = stubIntersectionObserver();

    render(<Sentinel />);

    const [active] = observer.active();
    expect(observer.active()).toHaveLength(1);
    expect(active?.options).toEqual({ rootMargin: '200px' });
    expect(active?.targets).toContain(screen.getByText('hidden'));
  });

  it('reports whether the element intersects', () => {
    const observer = stubIntersectionObserver();
    render(<Sentinel />);

    observer.setIntersecting(true);
    expect(screen.getByText('visible')).toBeInTheDocument();

    observer.setIntersecting(false);
    expect(screen.getByText('hidden')).toBeInTheDocument();
  });

  it('disconnects when the element unmounts', () => {
    const observer = stubIntersectionObserver();
    const { unmount } = render(<Sentinel />);

    unmount();

    expect(observer.active()).toHaveLength(0);
  });

  it('never reports an intersection where the API is missing', () => {
    // jsdom itself has no IntersectionObserver.
    expect('IntersectionObserver' in window).toBe(false);

    render(<Sentinel />);

    expect(screen.getByText('hidden')).toBeInTheDocument();
  });
});
