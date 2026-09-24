<?php

declare(strict_types=1);

use App\Rules\CompleteJpeg;
use Illuminate\Support\Facades\Validator;

function passes_complete_jpeg(string $contents): bool
{
    return Validator::make(['file' => uploaded_file($contents, 'photo.jpg')], ['file' => [new CompleteJpeg]])->passes();
}

function jpeg_blob(int $interlace = Imagick::INTERLACE_NO): string
{
    $image = new Imagick;
    $image->newPseudoImage(600, 600, 'gradient:#3a6ea5-#f4a261');
    $image->setImageFormat('jpeg');
    $image->setInterlaceScheme($interlace);

    return $image->getImageBlob();
}

it('accepts a complete JPEG', function (string $contents): void {
    expect(passes_complete_jpeg($contents))->toBeTrue();
})->with([
    'baseline' => fn (): string => fixture_contents('valid.jpg'),
    'with EXIF and IPTC' => fn (): string => fixture_contents('exif-iptc.jpg'),
    // Several scans with tables in between; only the last one ends with EOI.
    'progressive' => fn (): string => jpeg_blob(Imagick::INTERLACE_PLANE),
    // Decoders ignore anything after EOI; so does the size limit test (jpeg_of_size()).
    'with trailing bytes after EOI' => fn (): string => fixture_contents('valid.jpg').str_repeat("\0", 1000),
]);

it('rejects a JPEG without the end of its image data', function (string $contents): void {
    expect(passes_complete_jpeg($contents))->toBeFalse();
})->with([
    'cut in the middle of the scan' => fn (): string => substr(fixture_contents('valid.jpg'), 0, intdiv(strlen(fixture_contents('valid.jpg')), 2)),
    'only EOI missing' => fn (): string => substr(fixture_contents('valid.jpg'), 0, -2),
    'progressive, cut after the first scan' => function (): string {
        $jpeg = jpeg_blob(Imagick::INTERLACE_PLANE);
        $secondScan = strpos($jpeg, "\xFF\xDA", (int) strpos($jpeg, "\xFF\xDA") + 2);

        return substr($jpeg, 0, (int) $secondScan);
    },
    // The EXIF segment of exif-iptc.jpg holds binary values; an EOI inside a header segment
    // must not count as the end of the image.
    'EOI only inside a header segment' => fn (): string => "\xFF\xD8\xFF\xE1\x00\x06\xFF\xD9\x00\x00\xFF\xDA\x00\x02\x12\x34",
    'cut inside the header segments' => fn (): string => substr(fixture_contents('exif-iptc.jpg'), 0, 100),
    'no start of scan' => fn (): string => "\xFF\xD8\xFF\xD9",
]);

// Other types need no such check: their decoders fail on a truncated body.
it('ignores content that is not a JPEG', function (string $name): void {
    expect(passes_complete_jpeg(substr(fixture_contents($name), 0, 1000)))->toBeTrue();
})->with(['valid.png', 'valid.webp']);

it('explains the failure', function (): void {
    $validator = Validator::make(
        ['file' => uploaded_file(substr(fixture_contents('valid.jpg'), 0, -2), 'photo.jpg')],
        ['file' => [new CompleteJpeg]],
    );

    expect($validator->errors()->first('file'))->toBe('The file is incomplete or damaged.');
});
