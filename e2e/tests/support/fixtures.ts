import { randomUUID } from 'node:crypto';
import { readFile } from 'node:fs/promises';
import path from 'node:path';

// The backend's fixtures (backend/tests/Fixtures), mounted into the runner by docker-compose.e2e.yml.
// The fallback is the same directory in a checkout, for a run outside the container.
const FIXTURES_DIR =
  process.env.E2E_FIXTURES_DIR ??
  path.resolve(import.meta.dirname, '../../../backend/tests/Fixtures');

export function fixturePath(name: string): string {
  return path.join(FIXTURES_DIR, name);
}

export function readFixture(name: string): Promise<Buffer> {
  return readFile(fixturePath(name));
}

// The tests share one database: a unique name makes every assertion about "this" image exact,
// whatever other tests (or earlier repetitions) stored.
export function uniqueName(prefix: string, extension: string): string {
  return `${prefix}-${randomUUID().slice(0, 8)}.${extension}`;
}
