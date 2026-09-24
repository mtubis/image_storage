<?php

declare(strict_types=1);

use App\Support\DownloadFilename;

it('keeps the original name and derives a printable ASCII fallback', function (string $originalName, string $name, string $fallback): void {
    $filename = DownloadFilename::from($originalName, 'jpg', ['jpg', 'jpeg']);

    expect($filename->name)->toBe($name)
        ->and($filename->fallback)->toBe($fallback)
        ->and($filename->fallback)->toMatch('/^[\x20-\x7e]+$/');
})->with([
    'plain ASCII' => ['Holiday photo.jpg', 'Holiday photo.jpg', 'Holiday photo.jpg'],
    'transliterated diacritics' => ['Zażółć gęślą jaźń.jpg', 'Zażółć gęślą jaźń.jpg', 'Zazolc gesla jazn.jpg'],
    // Str::ascii() drops what it cannot transliterate; ".jpg" alone would be a hidden file.
    'untransliterable stem' => ['日本語.jpg', '日本語.jpg', 'image.jpg'],
    'partly transliterable stem' => ['Foto 日本.jpg', 'Foto 日本.jpg', 'Foto.jpg'],
    'dot file' => ['.jpg', '.jpg', 'image.jpg'],
    // "%" in the fallback could be decoded as a percent-escape by the client.
    'percent sign' => ['100% sharp.jpg', '100% sharp.jpg', '100 sharp.jpg'],
    'double quote' => ['say "cheese".jpg', 'say "cheese".jpg', 'say "cheese".jpg'],
    // Symfony refuses path separators in both names; uploads strip them, but rows may not come from uploads.
    'path separators' => ['a\\b/c.jpg', 'a_b_c.jpg', 'a_b_c.jpg'],
    'control characters' => ["a\r\nX-Evil: 1\t.jpg", "a\r\nX-Evil: 1\t.jpg", 'a X-Evil: 1.jpg'],
]);

// The upload accepts any client name, so it may not match the detected type; a download saved
// as "photo.jpg" with WebP bytes, or without an extension, would open wrongly or not at all.
it('appends the detected extension when the name does not end with one of the type', function (string $originalName, string $name, string $fallback): void {
    $filename = DownloadFilename::from($originalName, 'webp', ['webp']);

    expect($filename->name)->toBe($name)
        ->and($filename->fallback)->toBe($fallback);
})->with([
    'matching extension' => ['photo.webp', 'photo.webp', 'photo.webp'],
    'matching extension, other case' => ['PHOTO.WEBP', 'PHOTO.WEBP', 'PHOTO.WEBP'],
    'another type\'s extension' => ['photo.jpg', 'photo.jpg.webp', 'photo.jpg.webp'],
    'no extension' => ['photo', 'photo.webp', 'photo.webp'],
    'trailing dot' => ['photo.', 'photo..webp', 'photo..webp'],
    'untransliterable, no extension' => ['日本語', '日本語.webp', 'image.webp'],
]);

it('accepts every extension of the type', function (string $originalName): void {
    expect(DownloadFilename::from($originalName, 'tif', ['tif', 'tiff'])->name)->toBe($originalName);
})->with(['scan.tif', 'scan.tiff']);

it('builds an attachment disposition with both names', function (): void {
    $disposition = DownloadFilename::from('Zażółć.jpg', 'jpg', ['jpg', 'jpeg'])->contentDisposition();

    expect($disposition)->toBe("attachment; filename=Zazolc.jpg; filename*=utf-8''Za%C5%BC%C3%B3%C5%82%C4%87.jpg");
});

it('omits filename* when the name is plain ASCII', function (): void {
    expect(DownloadFilename::from('Holiday photo.jpg', 'jpg', ['jpg', 'jpeg'])->contentDisposition())
        ->toBe('attachment; filename="Holiday photo.jpg"');
});

it('percent-encodes control characters, so they cannot break the header', function (): void {
    $disposition = DownloadFilename::from("a\r\nX-Evil: 1.jpg", 'jpg', ['jpg'])->contentDisposition();

    expect($disposition)->not->toMatch('/[\x00-\x1f\x7f]/')
        ->toContain("filename*=utf-8''a%0D%0AX-Evil%3A%201.jpg");
});
