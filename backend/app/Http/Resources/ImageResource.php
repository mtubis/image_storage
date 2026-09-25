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
 * The docblocks above the keys are the field descriptions in the API documentation. The URLs
 * are absolute because the frontend runs on another origin.
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
            /** ULID of the image. */
            'id' => $this->id,
            /** File name as uploaded, made safe for display (no control or bidi characters, at most 255 characters). */
            'original_name' => $this->original_name,
            /** Extension of the detected type (not taken from the file name), e.g. `jpg`, `tif`. */
            'extension' => $this->extension,
            /** Detected content type, e.g. `image/jpeg`; also the `Content-Type` of the download. */
            'mime_type' => $this->mime_type,
            /** Size of the original file in bytes. */
            'size_bytes' => $this->size_bytes,
            /** Width of the original in pixels as displayed: the EXIF orientation of a JPEG or TIFF is applied (portrait photos stored sideways report their upright width). */
            'width' => $this->width,
            /** Height of the original in pixels as displayed, like `width`. */
            'height' => $this->height,
            /** Absolute URL of a WebP thumbnail, also for formats browsers cannot display (TIFF). */
            'thumbnail_url' => Storage::disk('thumbnails')->url($this->thumbnail_path),
            /** Absolute URL of the original file (see "Download an image"). */
            'download_url' => route('v1.images.download', $this->id),
            /** Name given by the uploader. */
            'uploader_name' => $this->uploader_name,
            /** Air temperature in Katowice in °C at the upload hour. Fetched by a background job shortly after the upload; `null` until then, or if the weather service was unavailable. */
            'temperature_c' => $this->temperature_c,
            /** Upload time, ISO 8601 in UTC. */
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
