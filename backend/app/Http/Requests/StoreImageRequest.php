<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Data\StoreImageData;
use App\Rules\CompleteJpeg;
use App\Rules\DisplayableText;
use App\Support\UploadedFilename;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Dimensions;
use Illuminate\Validation\Rules\File;
use LogicException;

final class StoreImageRequest extends FormRequest
{
    /**
     * A comment right above a key is that field's description in the API documentation, so
     * notes for developers go next to the rule they explain.
     *
     * @return array<string, list<string|File|Dimensions|CompleteJpeg|DisplayableText>>
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
            /** Shown next to the image in the listing. Control and bidirectional formatting characters are not allowed. */
            'uploader_name' => [
                'required',
                'string',
                'max:100',
                new DisplayableText,
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
            originalName: UploadedFilename::normalize($file->getClientOriginalName(), $extension),
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
     * @return list<string>
     */
    private function allowedExtensions(): array
    {
        /** @var array<string, list<string>> $types */
        $types = config()->array('images.allowed_types');

        return array_merge(...array_values($types));
    }
}
