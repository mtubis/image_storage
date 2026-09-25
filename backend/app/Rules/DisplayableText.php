<?php

declare(strict_types=1);

namespace App\Rules;

use App\Support\UploadedFilename;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Text shown to other users as it is: no control characters and no bidi controls, the same
 * characters stripped from file names (a bidi override would display the text reversed).
 *
 * A rule object rather than a "regex:" string: Scramble would publish the PCRE character class
 * as an OpenAPI pattern, which ECMA-262 validators misread. The /u flag also makes invalid
 * UTF-8 fail here instead of later as a 500 (DB "Incorrect string value").
 */
final readonly class DisplayableText implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || preg_match('/^[^'.UploadedFilename::UNSAFE_CHARACTERS.']+$/u', $value) !== 1) {
            $fail('validation.regex')->translate();
        }
    }
}
