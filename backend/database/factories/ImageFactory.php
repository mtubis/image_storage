<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Image;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

/**
 * @extends Factory<Image>
 */
final class ImageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        /** @var array<string, list<string>> $allowedTypes */
        $allowedTypes = config('images.allowed_types');
        $mimeType = $this->pick(array_keys($allowedTypes));
        $extension = $this->pick($allowedTypes[$mimeType]);
        // Stored names are ULID-based, independent of the client-supplied original name.
        $storedName = Str::lower((string) Str::ulid());

        return [
            'original_name' => fake()->word().'.'.$extension,
            'extension' => $extension,
            'mime_type' => $mimeType,
            'size_bytes' => fake()->numberBetween(20_000, Config::integer('images.max_size_kb') * 1024),
            'width' => fake()->numberBetween(Config::integer('images.min_width'), 4000),
            'height' => fake()->numberBetween(Config::integer('images.min_height'), 4000),
            'original_path' => sprintf('originals/%s.%s', $storedName, $extension),
            'thumbnail_path' => sprintf('thumbnails/%s.webp', $storedName),
            'uploader_name' => fake()->name(),
            'uploader_email' => fake()->safeEmail(),
            'metadata' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function withMetadata(array $metadata = ['EXIF' => ['Make' => 'Canon', 'Model' => 'EOS 5D']]): self
    {
        return $this->state(fn (): array => ['metadata' => $metadata]);
    }

    public function withTemperature(float $celsius = 21.5): self
    {
        return $this->state(fn (): array => [
            'temperature_c' => $celsius,
            'temperature_fetched_at' => now(),
        ]);
    }

    /**
     * Faker's randomElement() is untyped; this keeps the element type for static analysis.
     *
     * @template T
     *
     * @param  list<T>  $items
     * @return T
     */
    private function pick(array $items): mixed
    {
        return $items[fake()->numberBetween(0, count($items) - 1)];
    }
}
