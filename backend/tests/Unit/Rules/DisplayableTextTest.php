<?php

declare(strict_types=1);

use App\Rules\DisplayableText;
use Illuminate\Support\Facades\Validator;

// Directly, not through a request: the TrimStrings middleware would hide edge whitespace.
function is_displayable_text(mixed $value): bool
{
    return Validator::make(['name' => $value], ['name' => [new DisplayableText]])->passes();
}

it('accepts real names', function (string $name): void {
    expect(is_displayable_text($name))->toBeTrue();
})->with([
    'ASCII' => 'Jan Kowalski',
    'Polish' => 'Zażółć Gęślą',
    // ZWNJ is part of the correct spelling of Persian names.
    'with a zero-width non-joiner' => "\u{0645}\u{06CC}\u{200C}\u{0631}\u{0648}\u{0632}",
]);

it('rejects text that would not display as written', function (mixed $value): void {
    expect(is_displayable_text($value))->toBeFalse();
})->with([
    'a trailing line feed' => "Jan\n",
    'a line feed inside' => "Jan\nKowalski",
    'a line separator' => "Jan\u{2028}Kowalski",
    'a paragraph separator' => "Jan\u{2029}Kowalski",
    'a right-to-left override' => "Jan\u{202E}ikslawoK",
    'invalid UTF-8' => "Caf\xE9",
    'not a string' => 42,
]);
