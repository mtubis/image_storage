<?php

declare(strict_types=1);

use App\Services\Images\MetadataSanitizer;

it('keeps JSON-safe values unchanged', function (mixed $value): void {
    expect(new MetadataSanitizer()->sanitize(['key' => $value]))->toBe(['key' => $value]);
})->with([
    'ASCII' => 'FixtureCam',
    'UTF-8 with diacritics' => 'Zażółć gęślą jaźń',
    'empty string' => '',
    // The only control characters that occur in text; json_encode() escapes each as 2 bytes.
    'tab, line feed, carriage return' => "Line 1\r\nLine\t2",
    'integer' => 6,
    'float' => 1.5,
    'boolean' => true,
    'null' => null,
    'rational as returned by ext-exif' => '72/1',
]);

it('wraps invalid UTF-8 and binary data in a base64 object that decodes to the original bytes', function (string $bytes): void {
    $sanitized = new MetadataSanitizer()->sanitize(['value' => $bytes]);

    expect($sanitized['value'])->toBe(['base64' => base64_encode($bytes)])
        ->and(base64_decode($sanitized['value']['base64'], true))->toBe($bytes);
})->with([
    'Latin-1' => "Caf\xE9 Optics",
    'binary' => "\x00\x01\x02\xFF\xFE\x80\x81\x7F",
    'truncated multibyte sequence' => "\xC5",
    'overlong encoding' => "\xC0\xAF",
    // Valid UTF-8, but binary: json_encode() would inflate most such bytes 6× ("\u0001").
    'C0 control characters' => "\x00\x04",
    'text with a single control character' => "Fixture\x01Cam",
    'escape sequence (IPTC coded character set)' => "\x1B%G",
]);

it('sanitizes nested arrays and keeps their keys', function (): void {
    $sanitized = new MetadataSanitizer()->sanitize([
        'IFD0' => ['Make' => "Caf\xE9", 'Model' => 'FixtureCam 500'],
        'GPS' => ['GPSLatitude' => ['50/1', '15/1', "\xFF"]],
    ]);

    expect($sanitized)->toBe([
        'IFD0' => ['Make' => ['base64' => base64_encode("Caf\xE9")], 'Model' => 'FixtureCam 500'],
        'GPS' => ['GPSLatitude' => ['50/1', '15/1', ['base64' => base64_encode("\xFF")]]],
    ]);
});

it('turns non-finite floats into strings, which JSON cannot represent otherwise', function (float $value, string $expected): void {
    expect(new MetadataSanitizer()->sanitize(['value' => $value]))->toBe(['value' => $expected]);
})->with([
    'NaN' => [NAN, 'NAN'],
    'infinity' => [INF, 'INF'],
    'negative infinity' => [-INF, '-INF'],
]);

it('always produces JSON-encodable output', function (): void {
    $sanitized = new MetadataSanitizer()->sanitize([
        'a' => "\xFF\xFE",
        'b' => ['c' => NAN, 'd' => "ok \u{2603}"],
        'e' => [[["\x80"]]],
    ]);

    expect(fn (): string => json_encode($sanitized, JSON_THROW_ON_ERROR))->not->toThrow(JsonException::class);
});
