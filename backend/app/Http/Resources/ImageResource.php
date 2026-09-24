<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Image;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * The public shape of an image. Deliberately an allow-list: the uploader's e-mail (PII),
 * storage paths and the raw metadata are never part of it.
 *
 * @mixin Image
 */
final class ImageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'original_name' => $this->original_name,
            'extension' => $this->extension,
            'mime_type' => $this->mime_type,
            'size_bytes' => $this->size_bytes,
            'width' => $this->width,
            'height' => $this->height,
            // Absolute: the frontend runs on another origin.
            'thumbnail_url' => Storage::disk('thumbnails')->url($this->thumbnail_path),
            'download_url' => route('v1.images.download', $this->id),
            'uploader_name' => $this->uploader_name,
            // Filled in by a queued job shortly after the upload; null until then or if unavailable.
            'temperature_c' => $this->temperature_c,
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
