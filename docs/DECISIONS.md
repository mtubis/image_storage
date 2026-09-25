# Decisions & trade-offs

The reasoning behind the non-obvious choices in this repository, grouped by topic. The
[README](../README.md#key-decisions) has a short summary. "Verified" means the behaviour was
checked against the real tool or library, not assumed from its documentation.

- [Architecture](#architecture)
- [Upload validation](#upload-validation)
- [Storage and consistency](#storage-and-consistency)
- [Thumbnails and image processing](#thumbnails-and-image-processing)
- [Metadata extraction](#metadata-extraction)
- [Air temperature (Open-Meteo)](#air-temperature-open-meteo)
- [API](#api)
- [Frontend](#frontend)
- [Testing](#testing)
- [Infrastructure and CI](#infrastructure-and-ci)
- [Tooling and dependencies](#tooling-and-dependencies)
- [Known limitations](#known-limitations)

## Architecture

- **Two independent apps in one repository.** The backend and frontend share nothing but the
  REST contract. They run on separate origins, and CORS is configured for exactly one frontend
  origin.
- **Thin controllers, one Action per side-effecting use case** (`StoreImage`, `DeleteImage`).
  Read-only use cases have no Action: the listing is a model scope (`Image::forListing()`) and
  the download is a single-action controller. An Action there would only forward a call.
- **Contracts for everything external or side-effecting** (`WeatherProvider`,
  `ImageMetadataExtractor`, `ThumbnailGenerator`), bound in `AppServiceProvider`. Actions and
  jobs depend on interfaces only. Arch tests enforce this: `App\Actions` and `App\Jobs` must not
  use `App\Services` or the HTTP server layer (requests, responses, uploads).
- **No repository layer over Eloquent.** It would wrap one query and add nothing in a Laravel
  application.
- **`final readonly` DTOs** (`StoreImageData`, `ImageMetadata`, `Thumbnail`, `TemperatureReading`)
  between layers instead of loose arrays.
- **`config/images.php` is the single source of truth** for types, size, dimensions, thumbnail
  size, ImageMagick limits and page size. Validation, thumbnails, the stored extension, the
  factory and the API documentation all derive from it. The frontend mirrors the values in
  `uploadConstraints.ts`, because the apps share no code. The server stays authoritative.
- **Uploader data lives on the `images` table**, not in an `uploaders` table. The requirement
  ties a name and e-mail to an upload, and there are no user accounts.
- **ULID primary keys**: public IDs that can't be enumerated, and they sort roughly by time.
  Routes accept only **lowercase** ULIDs. On MariaDB `id` is `char(26)` with a case-insensitive
  collation, so an upper-case spelling would find the image there but not on SQLite.
- **`Model::shouldBeStrict()` outside production and immutable dates** (`CarbonImmutable`).
  Lazy loading and missing attributes fail loudly in development and tests.

## Upload validation

- **The type is validated by content, not by the file name.** `File::types()` uses the `mimes`
  rule, which checks the extension of the MIME type detected by finfo. A PDF renamed to `.jpg`
  is rejected, and a WebP named `.jpg` is accepted and stored as WebP. `mimetypes` with exact
  MIME types was rejected because older libmagic reports BMP as `image/x-ms-bmp`, and
  `File::image()` was rejected because it excludes TIFF.
- **5 MB = 5120 KiB**, the unit of Laravel's `max` file rule.
- **An upper resolution limit, 10000 × 10000 px, as a decompression-bomb guard.** It isn't an
  assignment requirement: a flat 30000 × 30000 PNG fits into 5 MB but decodes to gigabytes of
  pixels. The limit bounds edges, not area, so ImageMagick resource limits back it up (see
  [Thumbnails](#thumbnails-and-image-processing)).
- **`bail` on `file`**: dimension errors are reported only for an accepted image type.
- **Truncated JPEGs are rejected** (`CompleteJpeg` rule). libjpeg renders a truncated JPEG with
  grey rows and only a warning, which php-imagick doesn't expose, so the rule walks the header
  segments to the first SOS and requires an EOI marker. Bytes after EOI are allowed. This is
  strict by design: an encoder that omits the final EOI is refused too. For PNG, WebP, TIFF
  and BMP, missing pixel data makes the decoder fail, which also surfaces as a `422` (tested
  for each). A TIFF cut only after its pixel data (in trailing metadata) still decodes and is
  accepted: its image is intact.
- **`uploader_name` rejects control and bidirectional formatting characters** (`DisplayableText`
  rule). `string` + `max` accept them, and they would spoof or break the display. Only `Cc` and
  bidi controls are rejected, not all of `\p{C}`: format characters such as ZWNJ occur in real
  names. Invalid UTF-8 fails the same rule.
- **`email:rfc`, not `strict` or `dns`**: no network lookups during validation. Addresses such
  as `jan@localhost` are accepted.
- **Web server limits are 10 MB** (`upload_max_filesize`, `post_max_size`,
  `client_max_body_size`), so a 6 MB file gets Laravel's `422` with a field error rather than a
  `413`. For bodies over 10 MB nginx answers its own `413`. It is rendered as JSON with CORS
  headers, because nginx's default HTML page without CORS surfaces in the browser as an opaque
  network error.

## Storage and consistency

- **Dedicated disks**: `originals` (private, served only by the download endpoint) and
  `thumbnails` (public, served by nginx). File names are `{ulid}.{ext}`, and every access goes
  through the Filesystem abstraction. The default `local` disk has `serve => false`, because its
  root contains the originals.
- **The upload is processed in memory.** `StoreImageData` carries the file's bytes: the
  thumbnail generator, the metadata extractor and the disk all take a string. That is simple
  and fine at 5 MB. Larger files would call for streams and temporary files.
- **The thumbnail is generated in the request, not in a job.** Decoding the image is the last
  validation step: a file that passes the rules but can't be decoded gets a `422`, not a stored
  record with a broken thumbnail. The list can also show the new image at once. The cost is
  request time: 0.1–2.6 s for a 10000² image, depending on the format (measured).
- **Upload: files first, then one INSERT, no transaction.** A single INSERT is atomic. Any
  failure up to and including the INSERT deletes both stored files. Each deletion is
  best-effort and reported, and the original exception is rethrown.
- **The weather job is dispatched after the row exists, outside that cleanup.** A queue failure
  must not delete the files of a stored image, and the temperature is optional. So the failure
  is reported and the upload still returns `201`. The job implements `ShouldQueueAfterCommit`,
  so a dispatch inside a transaction never reaches a worker before the row does.
- **Delete: the row first, the files after commit.** A failure in between leaves an orphaned
  file nobody sees, rather than a listed image whose download fails. File removal is
  best-effort: a failure is reported, the other file is still removed and the response stays
  `204`. Orphans are left for monitoring or a future sweep.
- **The client's file name is kept for display and download only.** It is reduced to a basename,
  invalid UTF-8 is replaced by `?`, and control and bidi characters are removed. It is cut to
  255 characters while keeping an extension of up to 16 characters, and falls back to
  `image.{ext}`. The stored extension and MIME type are those of the **detected** type.
- **The uploader's e-mail is stored but never returned by the API** (PII). `ImageResource` is an
  allow-list, and the model hides the e-mail and storage paths as well. Database exceptions mask
  bindings, so a failed INSERT doesn't log the e-mail.

## Thumbnails and image processing

- **WebP thumbnails generated on the server** (longest edge 400 px, quality 80) for every format.
  Browsers can't display TIFF, and the list shouldn't download 5 MB originals.
- **intervention/image v4 with the Imagick driver.** GD can't read TIFF. Laravel 13's own
  `Illuminate\Image` wraps the same library, but its driver whitelists MIME types without
  `image/tiff`.
- **Decoding is done by native Imagick, then handed to Intervention.** Intervention's decoder
  detects the coder itself, decodes every page and clones the full-size pixels, and a valid
  10000² PNG/WebP/TIFF failed under the resource limits. The generator reads
  `"{CODER}:{file}[0]"`: the coder is forced from the detected type, and only the first page is
  read. It downscales natively (with JPEG shrink-on-load), then lets Intervention orient, strip
  and encode.
- **The generator refuses any type validation would refuse**, using the same detection. The
  `php` image has Ghostscript, and without this guard ImageMagick really decoded the PDF fixture.
- **ImageMagick resource limits are process-wide** and set at boot: width/height at the
  validation maximum, memory 256 MiB, map 512 MiB, disk 1 GiB. Memory or area limits alone only
  make ImageMagick spill to disk, so the disk limit is the hard cap (measured). The sum fits one
  full-size copy of a 10000² image, which is all the generator keeps. A test with scaled-down
  limits guards this property.
- **Thumbnails are auto-oriented from EXIF, and the API's `width`/`height` are the displayed
  dimensions** (edges swapped for orientations 5–8). They are read from the header, because
  Imagick's size is wrong after JPEG shrink-on-load. ImageMagick ignores EXIF orientation in PNG
  `eXIf` and WebP `EXIF` chunks, so those images are neither rotated nor swapped. This is pinned
  by tests.
- **Metadata is stripped from thumbnails, because they are public. An RGB source's ICC profile
  is kept.** CMYK sources are converted without colour management and lose their CMYK profile,
  so colours shift. This is pinned by a
  test; see [Known limitations](#known-limitations).
- **Thumbnails are cached for a year as `immutable`**, because a ULID file name is never
  rewritten. Removing a deleted image from a CDN would be a purge, not a header change.

## Metadata extraction

- **"Stored raw" means as decoded by PHP's readers, not interpreted.** `exif` is ext-exif's
  tag map, `iptc` the `iptcparse()` output. Nothing is converted, renamed or normalised: for
  example rationals stay `"72/1"`, dates stay `"2024:05:01 12:34:56"`, and tag names are ext-exif's
  (the Exif 2.1 names `ExifImageWidth`/`ExifImageLength`). The original segment bytes (APP1,
  APP13, `eXIf`) are not stored separately. They remain in the unchanged original, which the
  download serves, so any tool can re-read them. The cost is whatever the readers don't
  decode: a `MakerNote` of a vendor ext-exif doesn't know is `null`. Storing each segment as
  base64 as well was considered, but it would duplicate the original file inside the database.
- **One shape for every format: `{"exif": {…}, "iptc": {…}}`.** `exif` holds ext-exif's
  sections (`IFD0`, `EXIF`, `GPS`, …). `iptc` holds the `iptcparse()` output of the JPEG APP13
  block, or of TIFF tag 33723. ext-exif's `FILE` and `COMPUTED` sections are dropped: they are
  derived by PHP, and `FILE` would leak the temporary file name. Empty maps are omitted, so a
  key is never both a JSON list and an object.
- **Values are stored raw, but valid JSON**:
  - Invalid UTF-8 becomes `{"base64": "…"}`. A bare base64 string couldn't be told apart from
    text.
  - Strings with C0 control characters other than tab, LF and CR are treated as binary and
    wrapped the same way. Examples are the IPTC record version `\x00\x04` and the `1#090`
    escape sequence.
  - Keys are trusted, because PHP generates them.
- **PNG and WebP EXIF are read by a small chunk reader** (`ExifChunkReader`), because
  ext-exif returns `false` for them. The extracted EXIF block is a TIFF structure. It is passed
  to `exif_read_data()` as a stream, so every format produces the same keys. Every chunk length
  is bounds-checked. `Imagick::pingImageBlob()` was rejected: it does not expose a WebP's EXIF
  profile (verified). The extractor needs no ImageMagick at all.
- **ext-exif warnings are muted by a scoped error handler.** Laravel turns warnings into
  exceptions, which would lose a partial result, for example IPTC that survives a broken EXIF
  IFD.
- **The TIFF IPTC tag stored as LONG (Photoshop style) is repacked into bytes** in the file's
  byte order, since ext-exif returns it as integers. It is removed from `exif` only when it
  parsed into `iptc`, so an unparseable block isn't lost.
- **Metadata size is capped at 8 MiB of JSON** (`images.max_metadata_bytes`); beyond it the
  upload gets a `422` on `file`. ext-exif turns a crafted numeric array into text about 3.4× the
  size of the file: 17.7 MB for a 5 MB TIFF, which is over MariaDB's 16 MiB
  `max_allowed_packet`. The cap is checked on the exact JSON the model will store, before any
  file is written. Rejecting was chosen over raising `max_allowed_packet`, since only crafted
  files reach it.
- **IPTC's coded character set (`1:90`) is not applied.** Values are stored as `iptcparse()`
  returns them. UTF-8 text (the common case, marked `ESC % G`) stays text. Legacy Latin-1 text
  is invalid UTF-8, so it is base64-wrapped rather than transcoded, and can be decoded later
  with the stored `1#090` value.
- **Missing metadata is stored as `NULL`, never an error.** BMP has none, for example.

## Air temperature (Open-Meteo)

- **Fetched by a queued job** (`FetchImageTemperature`), so a slow or failing weather service
  never delays or fails an upload. `temperature_c` is `null` until the job runs, or for good if
  it gives up.
- **The stored value is for the upload's hour, not the job's.** Open-Meteo returns 24 hourly
  values for the current Warsaw day. The job picks the one for `created_at`, so a delayed retry
  still records the temperature at upload time.
- **The hour is matched using the response's `utc_offset_seconds`**, not a separate
  Europe/Warsaw conversion. Against the real API, DST change days still return 24 wall-clock
  labels and a single offset for the whole answer. The lookup key is "upload instant floored to
  the UTC hour + `utc_offset_seconds`". That equals a Warsaw conversion on every other day and
  stays consistent with the response on change days. Tests cover both offsets on the spring day
  and the repeated hour in autumn.
- **Failures are split by retryability**:
  - `WeatherRequestFailed` covers connection errors, timeouts, 5xx, 429 and malformed payloads.
    These are retried: once by the HTTP client (250 ms), then by the job (4 tries, backoff
    10 s / 60 s / 300 s, 30 s timeout).
  - `WeatherRequestRejected` (other 4xx) and `TemperatureNotAvailable` (hour missing or `null`)
    are permanent. They log a warning and finish.
  - The payload is validated strictly: list shapes, equal lengths, unit `°C`. Anything else is
    malformed, never a silently wrong temperature.
- **`WEATHER_PROVIDER` selects `open-meteo` (default) or `fake`**, which E2E uses. An unknown
  value throws instead of falling back. A typo must neither cause real network calls in tests
  nor store fake data in production.
- **The app runs in UTC.** Europe/Warsaw appears only in the Open-Meteo request, and MariaDB
  sessions are pinned to `+00:00`, so `TIMESTAMP` conversion doesn't depend on the server's
  time zone.

## API

- **Cursor pagination, newest first** (`created_at desc, id desc`), with a matching composite
  index. It gives stable infinite scroll while images are added or deleted, and the ID breaks
  ties within one second. Ordering by ULID alone was rejected: list order is a business property
  (upload time), not a detail of how IDs are encoded.
- **A cursor must have the listing's shape, otherwise it is a `422`.** Its keys, date format,
  lowercase ULID and direction flag are checked. Laravel silently returns page 1 for an
  undecodable cursor and throws a `500` for a decodable one with foreign keys. Cursors are not
  signed: a hand-made cursor of the right shape is simply a valid position in the list. The page size can't be set by the client.
- **The listing selects its columns explicitly.** Metadata (a potentially large JSON value) and
  the e-mail are never loaded for the list. Strict mode turns a forgotten column into an
  exception in tests.
- **Downloads use our own `Content-Disposition` builder** (`DownloadFilename`):
  - The UTF-8 name goes in `filename*`, with a printable-ASCII fallback in `filename`.
  - Laravel's default turns `日本語.jpg` into `.jpg`, and a backslash into a `500`.
  - If the name doesn't end with an extension of the detected type, that extension is appended.
    A WebP uploaded as `photo.jpg` downloads as `photo.jpg.webp`.
- **The original is served unchanged** (metadata included) with the stored `Content-Type`,
  `nosniff`, and a `Content-Security-Policy: default-src 'none'; sandbox` in case it is ever
  rendered inline. A missing original is a reported `500` rather than a `404`: it is an
  inconsistency that must reach monitoring.
- **A repeated `DELETE` is a `404`**, not an idempotent `204`. The frontend treats both as
  success.
- **All errors are JSON.** Every 404 is `{"message": "Not found."}`, because Laravel's message
  names the model class and echoes the ID even in production. Validation errors keep Laravel's
  `422` structure. Error responses carry CORS headers, so the SPA can read them.
- **CORS is an allow-list**: only `FRONTEND_URL` (never `*`), methods `GET, POST, DELETE`,
  explicit request headers, `Content-Disposition` exposed, no credentials.
- **The OpenAPI documentation (dedoc/scramble) is public** at `/docs/api`. The API has no
  authentication, so the document reveals nothing the API doesn't. Gaps in the inferred spec
  were fixed with small transformers, and `ApiDocumentationTest` checks the spec against real
  responses. `scramble:analyze` runs in `make lint-be` and CI.

## Frontend

- **Server state only through TanStack Query.** Responses are parsed with zod at the boundary,
  and the schemas keep only the fields the UI uses. `extension`/`mime_type` are plain strings,
  so a format added on the server doesn't break the list. `VITE_API_URL` is validated at
  startup and fails fast with a clear message.
- **Retry policy**: only network errors, timeouts and 5xx are retried (twice). 4xx and parse
  errors fail immediately.
- **Infinite scroll = an IntersectionObserver sentinel that only calls `fetchNextPage`.** The
  query owns the request. The effect re-runs after every page, because the observer fires only
  on visibility changes and a sentinel still in view (on a tall screen) must load again. A
  failed page is not retried automatically, since that would loop while in view. The "Load
  more" button becomes "Try again", and it doubles as a keyboard fallback.
- **Client validation mirrors the server but is never stricter.** The type passes if either the
  MIME type or the extension is allowed (Linux reports BMP as `image/x-bmp`). The e-mail check
  is only `something@something`, because `z.email()` and even the HTML5 rule reject addresses
  that `email:rfc` accepts.
- **Dimensions are read with `<img>` and an object URL**, not `createImageBitmap()`, which would
  allocate every pixel (400 MB for 10000²). When the browser can't decode the file (TIFF outside
  Safari), the check is skipped and the server decides.
- **Download is a plain link to `download_url`.** The browser streams the file with its own
  progress, and the API's `Content-Disposition: attachment` names it. A `download` attribute
  would be ignored cross-origin.
- **Delete is confirmed inline in the card**, not with `window.confirm`. Focus is managed
  throughout: Cancel is focused first, Escape cancels, and after deletion focus moves to the
  neighbouring card. The list cache is updated in place, without a reload. A `404` counts as
  already deleted.
- **Accessibility**: labelled inputs, the first invalid field focused, errors in live regions,
  deletions announced, busy buttons marked `aria-disabled` rather than `disabled`, so focus
  doesn't drop to `<body>`. `eslint-plugin-jsx-a11y` is enforced.

## Testing

- **Pest with arch tests** (`php`, `laravel` and `security` presets plus project layering rules).
  Larastan runs at level `max` with strict rules and no baseline.
- **Tests upload real files, never `UploadedFile::fake()`.** The fake reports its MIME type from
  the file *name*, so content sniffing would pass for any bytes. Fixtures for every format are
  generated by a script (`backend/tests/Fixtures/generate.php`). EXIF/IPTC are written byte by
  byte, including invalid UTF-8, binary values and orientation 6. Tests pin properties, never
  hashes. See `backend/tests/Fixtures/README.md`.
- **Per-format PHP behaviour is pinned** (`FixturesTest`): which formats ext-exif reads, where
  TIFF IPTC appears, and so on. A PHP or ImageMagick upgrade that changes it fails loudly.
- **No real network calls**, enforced by `Http::preventStrayRequests()` in the base test case
  and by MSW's `onUnhandledRequest: 'error'`. It doesn't rely on discipline.
- **The suite runs on SQLite (fast) and on MariaDB (the production engine).** MariaDB exposed
  real differences: collation, `TIMESTAMP` time zones, packet size. `phpunit.xml` uses
  `<server>` variables: `<env>` doesn't override the container's environment, and the suite
  would otherwise run against the development database. A test guards this.
- **Job tests drive the real `database` queue** (`queue:work --once`). Retries, backoff, failed
  jobs and after-commit behaviour are the framework's, not re-implemented by the test.
- **Critical guards were checked by mutation**: the test was confirmed to fail with the guard
  removed.
- **E2E covers only critical paths** (upload, rejected upload, infinite scroll, download
  byte-for-byte with a non-ASCII name, delete, web server limits). It runs against the
  **production build** of the frontend, in its own Compose project with a throwaway database.
  Tests clean up after themselves, so they can repeat against one seeded database. It uses one
  worker and no retries, because a retry would hide flakiness.

## Infrastructure and CI

- **Everything runs in Docker through the Makefile.** No PHP, Composer or Node is needed on the
  host. Containers run as the host user's UID/GID, so bind-mounted files aren't root-owned.
- **A fresh clone needs one command** (`make setup`). It runs `composer install` in a one-off
  container, then the migrations in another one, then starts the stack. The `php` entrypoint
  creates `.env` and the app key on first run. Running migrations before the stack starts
  means the queue worker never polls a missing `jobs` table, and `.env` is created once
  instead of by `php` and `queue` in parallel. `up --wait` includes the frontend's first
  `npm ci`, through a healthcheck on the Vite server.
- **Host UID/GID are passed into the images even when the ID already exists there.** macOS
  users are in group 20, which is `dialout` in Debian and Alpine. The `php` image uses
  `groupmod -o`; the Alpine frontend image reuses the existing group.
- **The `php` image ships ImageMagick 7.1.** Ubuntu's packages ship ImageMagick 6, and TIFF,
  WebP and EXIF handling depends on the version and its delegates. So CI's backend job runs in
  the project's own image instead of `setup-php`. ext-pcntl is installed, so the queue worker
  enforces job timeouts and stops gracefully.
- **`make check` runs the same checks as CI, except E2E** (`make e2e`). CI actions are pinned to commit
  SHAs with read-only permissions. Image layers are cached through the GitHub Actions cache.
- **The E2E stack is a separate Compose project** (ports 8001/5174, MariaDB on tmpfs) and runs
  next to the development stack without touching its data. The database is reset by
  `make e2e`, not by Playwright, because the runner container has no Docker access. The
  Playwright image and `@playwright/test` are pinned to the same version.
- **MariaDB is published on `127.0.0.1` only**, for a local GUI client.

## Tooling and dependencies

- **Permissive licences only.** Direct dependencies are MIT, except TypeScript and Playwright
  (Apache-2.0). Among transitive dev-only packages there are also MPL-2.0 (`lightningcss`,
  `axe-core`), CC-BY-4.0 (`caniuse-lite`, data) and Python-2.0 (`argparse`). None of them
  ships in the bundle. The project itself is MIT.
- **ESLint 9, not 10**: `eslint-plugin-jsx-a11y` requires ESLint 9. **TypeScript 6.0**:
  `typescript-eslint` doesn't support 7 yet. ESLint replaced the template's oxlint, because
  type-aware `strictTypeChecked` rules are required.
- **Rector** with PHP sets, prepared sets and `rector-laravel`. **Pint** with the `laravel`
  preset plus `declare_strict_types`, `final_class` and `strict_comparison`.

## Known limitations

- **CMYK images are converted to RGB without colour management**, so colours shift in the
  thumbnail (the original is untouched). ICC-managed conversion needs a bundled sRGB profile
  with a suitable licence.
- **XMP and PNG text chunks are not extracted.** The requirement names EXIF and IPTC.
- **A `MakerNote` of a vendor ext-exif doesn't know is stored as `null`.** It is still in the
  original file.
- **IPTC is read from JPEG (`APP13`) and TIFF (tag 33723) only**, not from PNG or WebP. PHP
  keeps only the first `APP13` segment, so an IPTC block split across several is truncated.
- **Multi-page TIFF**: the metadata extractor sees page 2 as ext-exif's `THUMBNAIL` section and
  doesn't read pages 3+. The thumbnail shows page 1.
- **`forecast_days=1`** (prescribed by the assignment URL) covers only the current Warsaw day.
  A retry after local midnight for an upload just before it stores `null`.
- **No ImageMagick time or thread limit.** Its time limit is an elapsed-time budget, not clearly
  per operation, and could misfire in long-lived workers. This needs load testing first.
- **Orphaned files after a failed deletion aren't cleaned up automatically.** They are reported;
  a sweep command is future work.
- **E2E runs in Chromium only.**
