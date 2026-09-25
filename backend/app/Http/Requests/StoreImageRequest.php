<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Data\StoreImageData;
use App\Rules\CompleteJpeg;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Dimensions;
use Illuminate\Validation\Rules\File;
use LogicException;

final class StoreImageRequest extends FormRequest
{
    // Length of the images.original_name column, in characters.
    private const int MAX_ORIGINAL_NAME_LENGTH = 255;

    // A "suffix" longer than this is part of the name, not an extension worth keeping.
    private const int MAX_KEPT_SUFFIX_LENGTH = 16;

    // Control characters, plus the bidirectional controls (LRM/RLM, embeddings, overrides,
    // isolates) that make "photo\u{202E}gpj.exe" display as "photoexe.jpg".
    private const string UNSAFE_NAME_CHARACTERS = '/[\p{Cc}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u';

    /**
     * A comment right above a key is that field's description in the API documentation, so
     * notes for developers go next to the rule they explain.
     *
     * @return array<string, list<string|File|Dimensions|CompleteJpeg>>
     */
    public function rules(): array
    {
        return [
            'file' => [
                // Dimensions only make sense for an accepted image: a PDF reports its type, not also
                // "invalid dimensions". (Type and size share one File rule and may both be reported.)
                'bail',
                'required',
                // Extensions are matched against the *detected* content type ("mimes"), not the
                // client-supplied name: a PDF renamed to .jpg is rejected, a WebP named .jpg is not.
                File::types($this->allowedExtensions())->max(config()->integer('images.max_size_kb')),
                Rule::dimensions()
                    ->minWidth(config()->integer('images.min_width'))
                    ->minHeight(config()->integer('images.min_height'))
                    ->maxWidth(config()->integer('images.max_width'))
                    ->maxHeight(config()->integer('images.max_height')),
                // A truncated JPEG still decodes (with grey rows); other formats fail to decode.
                new CompleteJpeg,
            ],
            /** Shown next to the image in the listing. Control characters are not allowed. */
            'uploader_name' => [
                'required',
                'string',
                'max:100',
                // A /u regex fails on invalid UTF-8, which would otherwise surface later as a 500
                // (DB "Incorrect string value", json_encode). Only control characters (Cc) are
                // excluded: format characters such as ZWNJ are legitimate in names.
                'regex:/^\P{Cc}+$/u',
            ],
            /** Stored with the image, never returned by the API. */
            'uploader_email' => ['required', 'string', 'email:rfc', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.dimensions' => 'The :attribute must be between :min_width×:min_height and :max_width×:max_height pixels.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'uploader_name' => 'name',
            'uploader_email' => 'e-mail',
        ];
    }

    public function toData(): StoreImageData
    {
        $file = $this->file('file');

        if (! $file instanceof UploadedFile) {
            throw new LogicException('toData() must only be called on a validated request.');
        }

        // The same detection the "mimes" rule accepted: the first extension Symfony maps to
        // the content-sniffed MIME type (so image/x-ms-bmp is "bmp" as well).
        $extension = (string) $file->guessExtension();

        return new StoreImageData(
            contents: $file->getContent(),
            originalName: $this->originalName($file, $extension),
            mimeType: $this->canonicalMimeType($extension),
            extension: $extension,
            uploaderName: $this->safe()->string('uploader_name')->toString(),
            uploaderEmail: $this->safe()->string('uploader_email')->toString(),
        );
    }

    /**
     * The key of config('images.allowed_types') for the extension, so aliases detected by an
     * older libmagic (image/x-ms-bmp) are stored under one name.
     */
    private function canonicalMimeType(string $extension): string
    {
        /** @var array<string, list<string>> $types */
        $types = config()->array('images.allowed_types');

        foreach ($types as $mimeType => $extensions) {
            if (in_array($extension, $extensions, true)) {
                return $mimeType;
            }
        }

        throw new LogicException("Extension [{$extension}] passed validation but is not an allowed type.");
    }

    /**
     * The client's file name, kept for display and the download only. It is not validated
     * (a strange name is no reason to refuse a valid image), so it is made storable here:
     * valid UTF-8, no control or bidi characters, at most the column's length.
     */
    private function originalName(UploadedFile $file, string $extension): string
    {
        $name = mb_scrub($file->getClientOriginalName(), 'UTF-8');
        $name = trim((string) preg_replace(self::UNSAFE_NAME_CHARACTERS, '', $name));

        if ($name === '') {
            return "image.{$extension}";
        }

        if (mb_strlen($name) <= self::MAX_ORIGINAL_NAME_LENGTH) {
            return $name;
        }

        // Shorten the stem, not the extension: "….jpg" still says what the file is.
        $dot = mb_strrpos($name, '.');
        $suffix = $dot === false ? '' : mb_substr($name, $dot);

        if (mb_strlen($suffix) > self::MAX_KEPT_SUFFIX_LENGTH) {
            $suffix = '';
        }

        return mb_substr($name, 0, self::MAX_ORIGINAL_NAME_LENGTH - mb_strlen($suffix)).$suffix;
    }

    /**
     * @return list<string>
     */
    private function allowedExtensions(): array
    {
        /** @var array<string, list<string>> $types */
        $types = config()->array('images.allowed_types');

        return array_merge(...array_values($types));
    }
}
