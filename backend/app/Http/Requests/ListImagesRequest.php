<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Rules\ImageListingCursor;
use Illuminate\Foundation\Http\FormRequest;

final class ListImagesRequest extends FormRequest
{
    /**
     * The page size is fixed by config/images.php; a client cannot choose it.
     *
     * @return array<string, list<string|ImageListingCursor>>
     */
    public function rules(): array
    {
        return [
            // Opaque, taken from meta.next_cursor / meta.prev_cursor of a previous page.
            'cursor' => ['nullable', new ImageListingCursor],
        ];
    }

    /**
     * The validated cursor, handed to the paginator explicitly so the value it uses is the one
     * validated here, not a second read of the raw input.
     */
    public function listingCursor(): ?string
    {
        $cursor = $this->validated('cursor');

        return is_string($cursor) ? $cursor : null;
    }
}
