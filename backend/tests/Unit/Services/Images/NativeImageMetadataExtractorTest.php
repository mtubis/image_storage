<?php

declare(strict_types=1);

use App\Contracts\ImageMetadataExtractor;
use App\Data\ImageMetadata;

function metadata_of(string $contents): ImageMetadata
{
    return resolve(ImageMetadataExtractor::class)->extract($contents);
}

/**
 * The IPTC record written into both exif-iptc fixtures (see tests/Fixtures/README.md).
 */
const FIXTURE_IPTC = [
    '1#000' => ["\x00\x04"],
    '1#090' => ["\x1B%G"],
    '2#000' => ["\x00\x04"],
    '2#005' => ['Zażółć gęślą jaźń'],
    '2#025' => ['katowice', 'fixture', 'żółw'],
    '2#080' => ['Jan Kowalski'],
    '2#116' => ['© 2024 Image Storage'],
    '2#120' => ['Fixture with EXIF and IPTC metadata.'],
];

describe('JPEG with EXIF and IPTC', function (): void {
    beforeEach(function (): void {
        $this->metadata = metadata_of(fixture_contents('exif-iptc.jpg'));
    });

    it('extracts the EXIF sections as returned by ext-exif', function (): void {
        expect($this->metadata->exif)->toHaveKeys(['IFD0', 'EXIF'])
            ->and($this->metadata->exif['IFD0'])->toMatchArray([
                'Model' => 'FixtureCam 500',
                'Orientation' => 6,
                'XResolution' => '72/1',
            ])
            ->and($this->metadata->exif['EXIF'])->toMatchArray([
                'DateTimeOriginal' => '2024:05:01 12:34:56',
                'ExifImageWidth' => 640,
                'ExifImageLength' => 500,
            ]);
    });

    it('keeps the raw bytes of values that are not valid UTF-8 as base64', function (): void {
        expect($this->metadata->exif['IFD0']['Make'])->toBe(['base64' => base64_encode("Caf\xE9 Optics")])
            ->and($this->metadata->exif['EXIF']['UserComment'])
            ->toBe(['base64' => base64_encode("\x00\x00\x00\x00\x00\x00\x00\x00\x00\x01\x02\xFF\xFE\x80\x81\x7F")]);
    });

    it('extracts the IPTC record from APP13', function (): void {
        expect($this->metadata->iptc)->toBe(FIXTURE_IPTC);
    });

    it('leaves out the sections PHP computes rather than reads from the file', function (): void {
        // FILE would also leak the name of the temporary upload file.
        expect($this->metadata->exif)->not->toHaveKeys(['FILE', 'COMPUTED']);
    });
});

it('extracts EXIF and IPTC from a TIFF, without duplicating the IPTC block in EXIF', function (): void {
    $metadata = metadata_of(fixture_contents('exif-iptc.tiff'));

    expect($metadata->exif['IFD0'])->toMatchArray(['Make' => 'FixtureCam', 'Artist' => 'Jan Kowalski'])
        ->and($metadata->exif['IFD0'])->not->toHaveKey('IPTC/NAA')
        ->and($metadata->exif['EXIF'])->toMatchArray(['DateTimeOriginal' => '2024:05:01 12:34:56'])
        ->and($metadata->iptc)->toBe(FIXTURE_IPTC);
});

it('extracts EXIF embedded in PNG and WebP, which ext-exif cannot read on its own', function (string $fixture): void {
    $metadata = metadata_of(fixture_contents($fixture));

    expect($metadata->exif['IFD0'])->toMatchArray([
        'Make' => 'FixtureCam',
        'Model' => 'FixtureCam 500',
        'Artist' => 'Jan Kowalski',
    ])
        ->and($metadata->exif['EXIF'])->toMatchArray(['DateTimeOriginal' => '2024:05:01 12:34:56'])
        ->and($metadata->iptc)->toBe([]);
})->with(['exif.png', 'exif.webp']);

it('returns the same shape for EXIF from PNG and WebP as ext-exif gives for JPEG', function (): void {
    // Values differ slightly: ImageMagick rewrote the resolution tags when writing exif.webp.
    $tagsBySection = fn (string $fixture): array => array_map(
        array_keys(...),
        metadata_of(fixture_contents($fixture))->exif,
    );

    expect($tagsBySection('exif.png'))->toBe($tagsBySection('exif.webp'))
        ->and($tagsBySection('exif.png'))->toBe([
            'IFD0' => ['Make', 'Model', 'XResolution', 'YResolution', 'ResolutionUnit', 'Software', 'DateTime', 'Artist'],
            'EXIF' => ['ExifVersion', 'DateTimeOriginal', 'FlashPixVersion', 'ColorSpace', 'ExifImageWidth', 'ExifImageLength'],
        ]);
});

it('returns empty metadata for an image without any', function (string $fixture): void {
    $metadata = metadata_of(fixture_contents($fixture));

    expect($metadata->isEmpty())->toBeTrue()
        ->and($metadata->toArray())->toBe([]);
})->with(['valid.jpg', 'valid.png', 'valid.webp', 'valid.bmp']);

it('keeps the structural tags every TIFF has, even without descriptive metadata', function (string $fixture): void {
    // Raw means raw: StripOffsets & co. are real IFD0 tags of the file.
    $metadata = metadata_of(fixture_contents($fixture));

    expect($metadata->exif)->toHaveKeys(['IFD0'])
        ->and($metadata->exif['IFD0'])->toMatchArray(['ImageWidth' => 500, 'ImageLength' => 500])
        ->and($metadata->exif['IFD0'])->not->toHaveKey('Make')
        ->and($metadata->iptc)->toBe([]);
})->with(['valid.tiff', 'multipage.tiff']);

it('reports the second page of a TIFF as THUMBNAIL, the only other IFD ext-exif reads', function (): void {
    // ext-exif follows IFD0 -> IFD1 only and names IFD1 after its JPEG meaning (the
    // thumbnail); in a multi-page TIFF it is page 2. Later pages are not read.
    $exif = metadata_of(fixture_contents('multipage.tiff'))->exif;

    expect(array_keys($exif))->toBe(['IFD0', 'THUMBNAIL'])
        ->and($exif['THUMBNAIL'])->toMatchArray(['ImageWidth' => 1200, 'ImageLength' => 300]);
});

it('extracts IPTC from a TIFF tag written as LONG, as Photoshop does', function (bool $bigEndian): void {
    $metadata = metadata_of(tiff_with_iptc_tag_as_long($bigEndian));

    expect($metadata->iptc)->toBe(FIXTURE_IPTC)
        ->and($metadata->exif)->toBe([]);
})->with(['little-endian (II)' => false, 'big-endian (MM)' => true]);

it('falls back to the IPTC tag in EXIF for a JPEG without APP13', function (): void {
    $metadata = metadata_of(jpeg_with_exif(tiff_with_iptc_tag_as_long(bigEndian: false)));

    expect($metadata->iptc)->toBe(FIXTURE_IPTC)
        ->and($metadata->exif)->toBe([]);
});

it('returns empty metadata for content that is not a supported image', function (string $contents): void {
    expect(metadata_of($contents)->isEmpty())->toBeTrue();
})->with([
    'empty' => '',
    'garbage' => str_repeat("\xDE\xAD\xBE\xEF", 16),
    'PDF' => fn (): string => fixture_contents('not-an-image.pdf'),
    'GIF' => fn (): string => fixture_contents('not-allowed.gif'),
]);

it('never fails on damaged metadata and still returns JSON-encodable output', function (string $contents): void {
    $metadata = metadata_of($contents);

    expect(json_encode($metadata->toArray(), JSON_THROW_ON_ERROR))->toBeString();
})->with([
    'JPEG with EXIF cut off mid-IFD' => jpeg_with_truncated_exif(),
    'JPEG with a bogus IFD0 entry count' => jpeg_with_bogus_ifd_count(),
    'PNG cut off inside the eXIf chunk' => cut_after(fixture_contents('exif.png'), 'eXIf', 20),
    'WebP cut off inside the EXIF chunk' => cut_after(fixture_contents('exif.webp'), 'EXIF', 20),
    'WebP with an EXIF chunk size beyond the file' => webp_with_oversized_exif_chunk(),
    'TIFF cut in half' => substr(fixture_contents('exif-iptc.tiff'), 0, 200),
]);

it('keeps what it could read when part of the metadata is damaged', function (): void {
    $metadata = metadata_of(jpeg_with_bogus_ifd_count());

    // ext-exif gives up on the damaged IFD0; the independent APP13 record is unaffected.
    expect($metadata->exif)->toBe([])
        ->and($metadata->iptc)->toBe(FIXTURE_IPTC);
});

it('restores the previous error handler after muting ext-exif warnings', function (): void {
    $current = static fn (): ?callable => tap(set_error_handler(null), static fn (): bool => restore_error_handler());
    $before = $current();

    metadata_of(jpeg_with_bogus_ifd_count());

    expect($current())->toBe($before);
});

it('produces JSON-encodable output for every fixture', function (string $fixture): void {
    $array = metadata_of(fixture_contents($fixture))->toArray();

    expect(json_decode(json_encode($array, JSON_THROW_ON_ERROR), true))->toBe($array);
})->with([
    'exif-iptc.jpg', 'exif-iptc.tiff', 'exif.png', 'exif.webp',
    'valid.jpg', 'valid.png', 'valid.webp', 'valid.tiff', 'valid.bmp', 'multipage.tiff',
]);

/**
 * The bytes up to $length bytes past the first occurrence of $marker.
 */
function cut_after(string $contents, string $marker, int $length): string
{
    $position = strpos($contents, $marker);

    if ($position === false) {
        throw new RuntimeException("Marker [{$marker}] not found.");
    }

    return substr($contents, 0, $position + strlen($marker) + $length);
}

/**
 * exif-iptc.jpg with its APP1 segment shortened: the declared segment length still matches,
 * but the IFD entries it points at are gone.
 */
function jpeg_with_truncated_exif(): string
{
    $jpeg = fixture_contents('exif-iptc.jpg');
    $tiffStart = (int) strpos($jpeg, "Exif\x00\x00") + 6;
    $keep = 30;
    $segment = "Exif\x00\x00".substr($jpeg, $tiffStart, $keep);

    // SOI, then a new APP1 with a consistent length, then everything after the original APP1.
    $originalLength = unpack('n', substr($jpeg, 4, 2))[1];

    return "\xFF\xD8\xFF\xE1".pack('n', strlen($segment) + 2).$segment.substr($jpeg, 4 + $originalLength);
}

/**
 * exif-iptc.jpg claiming 65535 entries in IFD0.
 */
function jpeg_with_bogus_ifd_count(): string
{
    $jpeg = fixture_contents('exif-iptc.jpg');
    $tiffStart = (int) strpos($jpeg, "Exif\x00\x00") + 6;
    $ifd0Offset = unpack('V', substr($jpeg, $tiffStart + 4, 4))[1];

    return substr_replace($jpeg, "\xFF\xFF", $tiffStart + $ifd0Offset, 2);
}

/**
 * exif.webp whose EXIF chunk header declares far more bytes than the file has.
 */
function webp_with_oversized_exif_chunk(): string
{
    $webp = fixture_contents('exif.webp');

    return substr_replace($webp, pack('V', 0x7FFFFFF0), (int) strpos($webp, 'EXIF') + 4, 4);
}

/**
 * A minimal TIFF whose only tag is 33723 (IPTC-NAA) holding the fixture IPTC record as LONG,
 * which makes ext-exif return it as integers decoded in the file's byte order.
 */
function tiff_with_iptc_tag_as_long(bool $bigEndian): string
{
    $iptc = exif_read_data(fixture_path('exif-iptc.tiff'))['IPTC/NAA'];
    // LONG counts whole 4-byte units; iptcparse() ignores the zero padding.
    $iptc = str_pad($iptc, (int) ceil(strlen($iptc) / 4) * 4, "\0");
    [$short, $long, $magic] = $bigEndian ? ['n', 'N', "MM\0*"] : ['v', 'V', "II*\0"];

    // Header (8 bytes), IFD0 at offset 8 with one entry (2 + 12 + 4 bytes), data at offset 26.
    return $magic.pack($long, 8)
        .pack($short, 1).pack($short.$short.$long.$long, 33723, 4, strlen($iptc) / 4, 26).pack($long, 0)
        .$iptc;
}

/**
 * valid.jpg with an APP1 EXIF segment holding the given TIFF structure, and no APP13.
 */
function jpeg_with_exif(string $tiff): string
{
    $payload = "Exif\x00\x00".$tiff;

    return "\xFF\xD8\xFF\xE1".pack('n', strlen($payload) + 2).$payload.substr(fixture_contents('valid.jpg'), 2);
}
