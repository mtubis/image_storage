// Binary units with IEC names, matching the backend where the 5 MB limit is 5120 KiB.
const UNITS = ['B', 'KiB', 'MiB', 'GiB', 'TiB'] as const;

const numberFormat = new Intl.NumberFormat('en', { maximumFractionDigits: 1, useGrouping: false });

export function formatBytes(bytes: number): string {
  if (!Number.isSafeInteger(bytes) || bytes < 0) {
    throw new RangeError(`Expected a non-negative integer number of bytes, got ${String(bytes)}.`);
  }

  let unitIndex = 0;
  let value = bytes;
  // Compare the rounded value, so that e.g. 1023.95 KiB becomes "1 MiB", not "1024 KiB".
  while (unitIndex < UNITS.length - 1 && roundToOneDecimal(value) >= 1024) {
    value /= 1024;
    unitIndex++;
  }

  const unit = UNITS[unitIndex];
  // Unreachable: the loop keeps the index in range (checked for noUncheckedIndexedAccess).
  if (unit === undefined) {
    throw new Error(`No unit at index ${String(unitIndex)}.`);
  }

  return `${numberFormat.format(value)} ${unit}`;
}

function roundToOneDecimal(value: number): number {
  return Math.round(value * 10) / 10;
}
