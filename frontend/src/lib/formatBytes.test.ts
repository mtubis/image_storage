import { describe, expect, it } from 'vitest';
import { formatBytes } from '@/lib/formatBytes';

describe('formatBytes', () => {
  it.each([
    [0, '0 B'],
    [1, '1 B'],
    [1023, '1023 B'],
    [1024, '1 KiB'],
    [1536, '1.5 KiB'],
    [6061, '5.9 KiB'],
    [5 * 1024 ** 2, '5 MiB'],
    [5 * 1024 ** 2 - 1, '5 MiB'],
    [3 * 1024 ** 3, '3 GiB'],
    [2 * 1024 ** 5, '2048 TiB'],
  ])('formats %d bytes as "%s"', (bytes, expected) => {
    expect(formatBytes(bytes)).toBe(expected);
  });

  // Picking the unit before rounding would print "1024 KiB" here.
  it.each([
    [1024 ** 2 - 1, '1 MiB'],
    [1024 ** 2 - 51, '1 MiB'],
    [1024 ** 3 - 1, '1 GiB'],
  ])('moves %d bytes to the next unit when rounding reaches it', (bytes, expected) => {
    expect(formatBytes(bytes)).toBe(expected);
  });

  it('does not group thousands', () => {
    expect(formatBytes(1023 * 1024)).toBe('1023 KiB');
  });

  it.each([-1, 1.5, Number.NaN, Number.POSITIVE_INFINITY])('rejects %d', (bytes) => {
    expect(() => formatBytes(bytes)).toThrow(RangeError);
  });
});
