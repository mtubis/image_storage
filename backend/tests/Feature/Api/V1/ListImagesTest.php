<?php

declare(strict_types=1);

use App\Models\Image;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\Fluent\AssertableJson;
use Illuminate\Testing\TestResponse;

// Listing via GET /api/v1/images: cursor pagination, newest first, 10 per page.

uses(RefreshDatabase::class);

beforeEach(function (): void {
    fake_image_storage();
    Queue::fake();
    $this->travelTo(new DateTimeImmutable('2026-09-25T08:30:00Z'));
});

/**
 * @return TestResponse<JsonResponse>
 */
function list_images(?string $cursor = null): TestResponse
{
    return test()->getJson('/api/v1/images'.($cursor === null ? '' : '?cursor='.urlencode($cursor)));
}

/**
 * Follow next_cursor from the first page to the last, like the infinite scroll does.
 *
 * @return list<list<string>> the image IDs of each page
 */
function list_all_pages(): array
{
    $pages = [];
    $cursor = null;

    do {
        $response = list_images($cursor)->assertOk();
        $pages[] = $response->json('data.*.id');
        $cursor = $response->json('meta.next_cursor');
    } while ($cursor !== null && count($pages) < 10);

    return $pages;
}

/**
 * @return list<string> IDs, newest first; the first image is the newest, one minute apart
 */
function create_images_minutes_apart(int $count): array
{
    return Image::factory()
        ->count($count)
        ->state(new Sequence(fn (Sequence $sequence): array => ['created_at' => now()->subMinutes($sequence->index)]))
        ->create()
        ->pluck('id')
        ->all();
}

it('returns an empty page when there are no images', function (): void {
    list_images()
        ->assertOk()
        ->assertJsonPath('data', [])
        ->assertJsonPath('meta.next_cursor', null)
        ->assertJsonPath('meta.per_page', 10);
});

it('pages through all images newest first, 10 per page, without duplicates', function (): void {
    $ids = create_images_minutes_apart(25);

    $pages = list_all_pages();

    expect(array_map(count(...), $pages))->toBe([10, 10, 5])
        ->and(array_merge(...$pages))->toBe($ids);
});

it('uses the id as a tie-breaker for images uploaded in the same second', function (): void {
    // created_at has second precision, so bursts of uploads share it.
    Image::factory()->count(12)->create(['created_at' => now()]);
    $expected = Image::query()->orderByDesc('id')->pluck('id')->all();

    $pages = list_all_pages();

    expect(array_map(count(...), $pages))->toBe([10, 2])
        ->and(array_merge(...$pages))->toBe($expected);
});

it('does not repeat items on the next page when an image is uploaded while scrolling', function (): void {
    $ids = create_images_minutes_apart(15);
    $firstPage = list_images()->assertOk();

    $this->travel(1)->minutes();
    Image::factory()->create();

    $secondPage = list_images($firstPage->json('meta.next_cursor'))->assertOk();

    expect($secondPage->json('data.*.id'))->toBe(array_slice($ids, 10))
        ->and($secondPage->json('meta.next_cursor'))->toBeNull();
});

it('does not skip items on the next page when the cursor item is deleted while scrolling', function (): void {
    $ids = create_images_minutes_apart(15);
    $firstPage = list_images()->assertOk();

    // The next cursor points at this item; an offset or an ID lookup would lose its place.
    Image::query()->findOrFail($ids[9])->delete();

    expect(list_images($firstPage->json('meta.next_cursor'))->json('data.*.id'))->toBe(array_slice($ids, 10));
});

it('lists every image in the same shape as the upload response', function (): void {
    $image = Image::factory()->withTemperature(-3.5)->create([
        'original_name' => 'Zażółć gęślą jaźń.tif',
        'extension' => 'tif',
        'mime_type' => 'image/tiff',
        'size_bytes' => 123_456,
        'width' => 640,
        'height' => 500,
        'uploader_name' => 'Jan Kowalski',
    ]);

    list_images()->assertOk()->assertJsonPath('data', [[
        'id' => $image->id,
        'original_name' => 'Zażółć gęślą jaźń.tif',
        'extension' => 'tif',
        'mime_type' => 'image/tiff',
        'size_bytes' => 123_456,
        'width' => 640,
        'height' => 500,
        'thumbnail_url' => config('app.url')."/storage/thumbnails/{$image->thumbnail_path}",
        'download_url' => config('app.url')."/api/v1/images/{$image->id}/download",
        'uploader_name' => 'Jan Kowalski',
        'temperature_c' => -3.5,
        'created_at' => '2026-09-25T08:30:00Z',
    ]]);
});

it('never exposes the e-mail, storage paths or metadata', function (): void {
    Image::factory()->withMetadata()->create(['uploader_email' => 'jan.kowalski@example.com']);

    $response = list_images()->assertOk();

    $response->assertJson(fn (AssertableJson $json): AssertableJson => $json->has('data.0', fn (AssertableJson $data): AssertableJson => $data
        ->missing('uploader_email')
        ->missing('original_path')
        ->missing('thumbnail_path')
        ->missing('metadata')
        ->etc())->etc());
    expect($response->getContent())
        ->not->toContain('jan.kowalski@example.com')
        ->not->toContain('Canon');
});

it('does not load the metadata or the e-mail from the database', function (): void {
    Image::factory()->withMetadata()->create();
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    list_images()->assertOk();

    expect($queries)->toHaveCount(1)
        ->and($queries[0])->not->toContain('*')
        ->not->toContain('metadata')
        ->not->toContain('uploader_email');
});

it('ignores a client-requested page size', function (): void {
    create_images_minutes_apart(15);

    $this->getJson('/api/v1/images?per_page=100')
        ->assertOk()
        ->assertJsonCount(10, 'data')
        ->assertJsonPath('meta.per_page', 10);
});

it('accepts the cursors it issues in both directions', function (): void {
    $ids = create_images_minutes_apart(15);
    $firstPage = list_images()->assertOk();

    $secondPage = list_images($firstPage->json('meta.next_cursor'))->assertOk();
    $backToFirst = list_images($secondPage->json('meta.prev_cursor'))->assertOk();

    expect($backToFirst->json('data.*.id'))->toBe(array_slice($ids, 0, 10));
});

it('treats an empty cursor as the first page', function (): void {
    $ids = create_images_minutes_apart(15);

    expect(list_images('')->assertOk()->json('data.*.id'))->toBe(array_slice($ids, 0, 10));
});

it('rejects a malformed cursor', function (string $cursor): void {
    list_images($cursor)
        ->assertUnprocessable()
        ->assertOnlyJsonValidationErrors(['cursor']);
})->with([
    'not base64 JSON' => ['definitely-not-a-cursor'],
    // Laravel treats JSON without its direction flag as "no cursor" and returns the first page.
    'JSON without the direction flag' => [rtrim(strtr(base64_encode('{"created_at":"2026-09-25 08:30:00","id":"01k62h0v8g8k3x7x9j3w7b2q4m"}'), '+/', '-_'), '=')],
]);

// Each case starts from a well-formed cursor, so exactly one parameter is wrong.
it('rejects a cursor with parameters the listing does not issue', function (array $overrides, array $removed = []): void {
    $parameters = array_diff_key(
        array_merge(['created_at' => '2026-09-25 08:30:00', 'id' => '01k62h0v8g8k3x7x9j3w7b2q4m'], $overrides),
        array_flip($removed),
    );

    list_images(new Cursor($parameters)->encode())
        ->assertUnprocessable()
        ->assertOnlyJsonValidationErrors(['cursor']);
})->with([
    'missing id' => [[], ['id']],
    'missing created_at' => [[], ['created_at']],
    'unknown parameter' => [['size_bytes' => 1]],
    'created_at as an array' => [['created_at' => ['2026-09-25 08:30:00']]],
    'created_at not a date' => [['created_at' => 'yesterday']],
    'created_at in another format' => [['created_at' => '2026-09-25T08:30:00Z']],
    'created_at overflowing' => [['created_at' => '2026-02-31 08:30:00']],
    'id as a number' => [['id' => 1]],
    'id not a ULID' => [['id' => '01k62h0v8g8k3x7x9j3w7b2q4']],
    // Stored IDs are lowercase; SQLite and MariaDB would order an uppercase one differently.
    'id in uppercase' => [['id' => '01K62H0V8G8K3X7X9J3W7B2Q4M']],
]);

it('rejects a cursor with a non-boolean direction flag', function (): void {
    $json = '{"created_at":"2026-09-25 08:30:00","id":"01k62h0v8g8k3x7x9j3w7b2q4m","_pointsToNextItems":"yes"}';

    list_images(rtrim(strtr(base64_encode($json), '+/', '-_'), '='))
        ->assertUnprocessable()
        ->assertOnlyJsonValidationErrors(['cursor']);
});

it('rejects a cursor given as an array', function (): void {
    $this->getJson('/api/v1/images?cursor[]=x')
        ->assertUnprocessable()
        ->assertOnlyJsonValidationErrors(['cursor']);
});
