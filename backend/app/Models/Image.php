<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ImageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $original_name
 * @property string $extension
 * @property string $mime_type
 * @property int $size_bytes
 * @property int $width
 * @property int $height
 * @property string $original_path
 * @property string $thumbnail_path
 * @property string $uploader_name
 * @property string $uploader_email
 * @property array<string, mixed>|null $metadata
 * @property float|null $temperature_c
 * @property CarbonImmutable|null $temperature_fetched_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'original_name',
    'extension',
    'mime_type',
    'size_bytes',
    'width',
    'height',
    'original_path',
    'thumbnail_path',
    'uploader_name',
    'uploader_email',
    'metadata',
    'temperature_c',
    'temperature_fetched_at',
])]
// The e-mail is PII and paths are internal storage details; the API exposes neither.
// Hidden here as well, so an accidental toArray()/toJson() can't leak them.
#[Hidden(['uploader_email', 'original_path', 'thumbnail_path'])]
final class Image extends Model
{
    /** @use HasFactory<ImageFactory> */
    use HasFactory, HasUlids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'metadata' => 'array',
            'temperature_c' => 'float',
            'temperature_fetched_at' => 'datetime',
        ];
    }
}
