<?php

declare(strict_types=1);

namespace App\Rules;

use App\Models\Image;
use Closure;
use DateTimeImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Str;

/**
 * Accepts only a cursor shaped like the ones the image listing issues.
 *
 * The paginator falls back to the first page for an undecodable cursor, which hides a client
 * bug, and throws (a 500) for a decodable one without the expected parameters. Values are
 * checked too: a non-date or non-ULID value would not fail, it would silently select a wrong
 * page (SQLite compares it as a string, MariaDB converts it with a warning).
 */
final readonly class ImageListingCursor implements ValidationRule
{
    private const string MESSAGE = 'The :attribute is not a valid listing cursor.';

    // How the paginator serialises CarbonImmutable (its __toString()).
    private const string CREATED_AT_FORMAT = 'Y-m-d H:i:s';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $cursor = is_string($value) ? Cursor::fromEncoded($value) : null;

        if (! $cursor instanceof Cursor || ! $this->isValid($cursor->toArray())) {
            $fail(self::MESSAGE);
        }
    }

    /**
     * @param  array<array-key, mixed>  $parameters
     */
    private function isValid(array $parameters): bool
    {
        // "_pointsToNextItems" is the paginator's internal direction flag, part of Cursor::toArray();
        // a rename in a framework upgrade is caught by the test that follows issued cursors.
        $expectedKeys = [...Image::LISTING_ORDER, '_pointsToNextItems'];
        $keys = array_keys($parameters);
        sort($keys);
        sort($expectedKeys);

        if ($keys !== $expectedKeys) {
            return false;
        }

        $createdAt = $parameters['created_at'];
        $id = $parameters['id'];

        return is_bool($parameters['_pointsToNextItems'])
            && is_string($createdAt) && $this->isCreatedAt($createdAt)
            // HasUlids stores lowercase IDs; SQLite and MariaDB would order an uppercase one differently.
            && is_string($id) && Str::isUlid($id) && $id === strtolower($id);
    }

    private function isCreatedAt(string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat('!'.self::CREATED_AT_FORMAT, $value);

        // A round trip rejects overflowing dates such as "2026-02-31", which PHP rolls over.
        return $date !== false && $date->format(self::CREATED_AT_FORMAT) === $value;
    }
}
