<?php

declare(strict_types=1);

use App\Support\UploadedFilename;

it('makes the client file name storable and safe to display', function (string $clientName, string $normalized): void {
    expect(UploadedFilename::normalize($clientName, 'jpg'))->toBe($normalized);
})->with([
    'plain' => ['Holiday photo.JPG', 'Holiday photo.JPG'],
    'invalid UTF-8' => ["ph\xC3\x28oto.jpg", 'ph?(oto.jpg'],
    'control characters' => ["pho\x00to\n.jpg", 'photo.jpg'],
    // U+202E would display the name as "invoicegpj.jpg" reversed, e.g. to fake an extension.
    'bidi override' => ["invoice\u{202E}gpj.jpg", 'invoicegpj.jpg'],
    'bidi isolates and marks' => ["a\u{2066}b\u{2069}c\u{200F}.jpg", 'abc.jpg'],
    'Arabic letter mark' => ["a\u{061C}b.jpg", 'ab.jpg'],
    // Displayed as line breaks, like LF.
    'line and paragraph separators' => ["a\u{2028}b\u{2029}c.jpg", 'abc.jpg'],
    // Format characters other than bidi controls occur in real names (Persian, Indic scripts).
    'zero-width non-joiner' => ["می\u{200C}خواهم.jpg", "می\u{200C}خواهم.jpg"],
    'surrounding whitespace' => ['  photo.jpg ', 'photo.jpg'],
]);

it('falls back to a generic name when nothing displayable is left', function (string $clientName): void {
    expect(UploadedFilename::normalize($clientName, 'webp'))->toBe('image.webp');
})->with([
    'empty' => '',
    'only control characters' => "\x00\x1F",
    'only bidi controls' => "\u{202E}\u{202D}",
]);

it('shortens a long name to the column length, keeping a short extension', function (string $clientName, string $normalized): void {
    expect(UploadedFilename::normalize($clientName, 'jpg'))->toBe($normalized)
        ->and(mb_strlen($normalized))->toBeLessThanOrEqual(255);
})->with([
    'multibyte stem' => [str_repeat('ł', 300).'.jpg', str_repeat('ł', 251).'.jpg'],
    'no extension' => [str_repeat('photo', 60), str_repeat('photo', 51)],
    // "." plus 16 characters is longer than an extension worth keeping: part of the name.
    'suffix too long to be an extension' => [str_repeat('a', 250).'.'.str_repeat('b', 16), str_repeat('a', 250).'.bbbb'],
    'exactly 255 characters' => [str_repeat('a', 251).'.jpg', str_repeat('a', 251).'.jpg'],
]);

it('lists the characters it strips as a regex character class', function (): void {
    expect(preg_match('/['.UploadedFilename::UNSAFE_CHARACTERS.']/u', "a\u{202E}b"))->toBe(1)
        ->and(preg_match('/['.UploadedFilename::UNSAFE_CHARACTERS.']/u', "a\u{2028}b"))->toBe(1)
        ->and(preg_match('/['.UploadedFilename::UNSAFE_CHARACTERS.']/u', "a\u{200C}b"))->toBe(0);
});
