<?php

declare(strict_types=1);

use App\Models\Image;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('uses a ULID primary key', function (): void {
    $image = Image::factory()->create();

    expect(Str::isUlid($image->id))->toBeTrue()
        ->and($image->getIncrementing())->toBeFalse()
        ->and($image->getKeyType())->toBe('string');
});

it('round-trips metadata as an array', function (): void {
    $metadata = [
        'EXIF' => ['Make' => 'Canon', 'ISOSpeedRatings' => 100],
        'IPTC' => ['2#120' => ['Zażółć gęślą jaźń 📷']],
    ];

    $image = Image::factory()->create(['metadata' => $metadata]);

    expect($image->fresh()?->metadata)->toBe($metadata);
});

it('stores non-ASCII metadata unescaped, so text takes no more bytes than in the file', function (): void {
    $image = Image::factory()->create(['metadata' => ['IPTC' => ['2#120' => ['Zażółć 📷']]]]);

    expect(DB::table('images')->where('id', $image->id)->value('metadata'))
        ->toBe('{"IPTC":{"2#120":["Zażółć 📷"]}}');
});

it('stores missing metadata as null', function (): void {
    $image = Image::factory()->create(['metadata' => null]);

    expect($image->fresh()?->metadata)->toBeNull();
});

it('casts numeric columns to native types', function (): void {
    $image = Image::factory()->withTemperature(-3.5)->create([
        'size_bytes' => 1_234_567,
        'width' => 70_000,
        'height' => 500,
    ])->fresh();

    expect($image?->size_bytes)->toBe(1_234_567)
        ->and($image?->width)->toBe(70_000)
        ->and($image?->height)->toBe(500)
        ->and($image?->temperature_c)->toBe(-3.5)
        ->and($image?->temperature_fetched_at)->toBeInstanceOf(DateTimeImmutable::class)
        ->and($image?->created_at)->toBeInstanceOf(DateTimeImmutable::class);
});

it('has no temperature until the weather job records it', function (): void {
    $image = Image::factory()->create()->fresh();

    expect($image?->temperature_c)->toBeNull()
        ->and($image?->temperature_fetched_at)->toBeNull();
});

it('never serializes the uploader e-mail or storage paths', function (): void {
    $image = Image::factory()->create(['uploader_email' => 'jane@example.com']);

    expect($image->toArray())->not->toHaveKeys(['uploader_email', 'original_path', 'thumbnail_path'])
        ->and($image->toJson())->not->toContain('jane@example.com')
        ->and($image->uploader_email)->toBe('jane@example.com');
});

it('indexes the cursor pagination order', function (): void {
    $columns = collect(Schema::getIndexes('images'))->pluck('columns');

    expect($columns)->toContain(['created_at', 'id']);
});

// Model::shouldBeStrict() outside production: a typo in an attribute name must fail loudly,
// not be silently dropped.
// Factories unguard the model, so fill() is exercised directly.
it('rejects unknown attributes', function (): void {
    new Image(['uploader_mail' => 'jane@example.com']);
})->throws(MassAssignmentException::class);
