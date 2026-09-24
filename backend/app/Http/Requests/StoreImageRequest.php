<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Dimensions;
use Illuminate\Validation\Rules\File;

final class StoreImageRequest extends FormRequest
{
    /**
     * @return array<string, list<string|File|Dimensions>>
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
            ],
            // A /u regex fails on invalid UTF-8, which would otherwise surface later as a 500
            // (DB "Incorrect string value", json_encode). Only control characters (Cc) are
            // excluded: format characters such as ZWNJ are legitimate in names.
            'uploader_name' => ['required', 'string', 'max:100', 'regex:/^\P{Cc}+$/u'],
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
