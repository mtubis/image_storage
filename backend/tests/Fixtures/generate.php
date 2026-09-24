<?php

declare(strict_types=1);

/*
 * Regenerates the committed image fixtures in this directory (see README.md).
 *
 *     make fixtures
 *
 * Pixels come from Imagick; metadata is written byte by byte. Imagick's TIFF writer drops
 * Make/Model/EXIF properties and has no way to write chosen raw EXIF values, so a minimal
 * TIFF/IFD encoder below builds both the TIFF fixtures and the JPEG EXIF (APP1) payload —
 * EXIF *is* a TIFF structure. Output is deterministic (no timestamps), so regenerating with
 * the same ImageMagick version produces identical files.
 */

const TIFF_ASCII = 2;
const TIFF_SHORT = 3;
const TIFF_LONG = 4;
const TIFF_RATIONAL = 5;
const TIFF_UNDEFINED = 7;

const TAG_EXIF_IFD_POINTER = 34665;

// Same bytes are asserted in tests/Unit/FixturesTest.php.
const BINARY_BYTES = "\x00\x01\x02\xFF\xFE\x80\x81\x7F";

/**
 * Plain vertical gradient, stripped of every profile, comment and timestamp.
 */
function gradient(int $width, int $height): Imagick
{
    $image = new Imagick;
    $image->newPseudoImage($width, $height, 'gradient:#3a6ea5-#f4a261');
    $image->stripImage();
    $image->setImageDepth(8);

    return $image;
}

function writeFixture(string $name, string $bytes): void
{
    if (file_put_contents(__DIR__.'/'.$name, $bytes) === false) {
        throw new RuntimeException("Could not write fixture {$name}.");
    }
}

function writeImage(Imagick $image, string $format, string $name, ?callable $configure = null): void
{
    $image = clone $image;
    $image->setImageFormat($format);

    if ($configure !== null) {
        $configure($image);
    }

    writeFixture($name, $image->getImagesBlob());
}

function jpegQuality(Imagick $image): void
{
    $image->setImageCompressionQuality(80);
}

/**
 * Encodes one IFD (little-endian) placed at $offset, followed by its out-of-line values.
 *
 * @param  array<int, array{int, string|list<int>|list<array{int, int}>}>  $entries  tag => [type, value]
 */
function encodeIfd(array $entries, int $offset): string
{
    ksort($entries); // TIFF 6.0 requires entries sorted by tag.

    $dataOffset = $offset + 2 + 12 * count($entries) + 4;
    $directory = pack('v', count($entries));
    $data = '';

    foreach ($entries as $tag => [$type, $value]) {
        [$bytes, $count] = match ($type) {
            TIFF_ASCII => [$value."\0", strlen($value) + 1],
            TIFF_UNDEFINED => [$value, strlen($value)],
            TIFF_SHORT => [pack('v*', ...$value), count($value)],
            TIFF_LONG => [pack('V*', ...$value), count($value)],
            TIFF_RATIONAL => [implode('', array_map(static fn (array $r): string => pack('V2', ...$r), $value)), count($value)],
        };

        $directory .= pack('vvV', $tag, $type, $count);

        if (strlen($bytes) <= 4) {
            $directory .= str_pad($bytes, 4, "\0");

            continue;
        }

        $directory .= pack('V', $dataOffset + strlen($data));
        // Values must start on a word boundary.
        $data .= $bytes.(strlen($bytes) % 2 === 1 ? "\0" : '');
    }

    return $directory.pack('V', 0).$data;
}

/**
 * Little-endian TIFF: header, optional payload (image strip) at offset 8, IFD0 and, when
 * given, an Exif sub-IFD linked from IFD0.
 *
 * @param  array<int, array{int, string|list<int>|list<array{int, int}>}>  $ifd0
 * @param  array<int, array{int, string|list<int>|list<array{int, int}>}>  $exifIfd
 */
function encodeTiff(array $ifd0, array $exifIfd = [], string $payload = ''): string
{
    $payload .= strlen($payload) % 2 === 1 ? "\0" : '';
    $ifd0Offset = 8 + strlen($payload);

    $exif = '';
    if ($exifIfd !== []) {
        // The pointer's own size is fixed, so encode once to learn IFD0's length, then again.
        $ifd0[TAG_EXIF_IFD_POINTER] = [TIFF_LONG, [0]];
        $exifOffset = $ifd0Offset + strlen(encodeIfd($ifd0, $ifd0Offset));
        $ifd0[TAG_EXIF_IFD_POINTER] = [TIFF_LONG, [$exifOffset]];
        $exif = encodeIfd($exifIfd, $exifOffset);
    }

    return "II*\0".pack('V', $ifd0Offset).$payload.encodeIfd($ifd0, $ifd0Offset).$exif;
}

/**
 * IFD0 tags of a plausible camera file (exif.png, exif.webp, exif-iptc.tiff).
 *
 * @return array<int, array{int, string|list<int>|list<array{int, int}>}>
 */
function cameraIfd0(): array
{
    return [
        271 => [TIFF_ASCII, 'FixtureCam'],                        // Make
        272 => [TIFF_ASCII, 'FixtureCam 500'],                    // Model
        282 => [TIFF_RATIONAL, [[72, 1]]],                        // XResolution
        283 => [TIFF_RATIONAL, [[72, 1]]],                        // YResolution
        296 => [TIFF_SHORT, [2]],                                 // ResolutionUnit: inch
        305 => [TIFF_ASCII, 'image-storage fixture generator'],   // Software
        306 => [TIFF_ASCII, '2024:05:01 12:34:56'],               // DateTime
        315 => [TIFF_ASCII, 'Jan Kowalski'],                      // Artist
    ];
}

/**
 * Exif sub-IFD with the tags Exif 2.32 marks as mandatory for uncompressed/compressed data.
 *
 * @return array<int, array{int, string|list<int>|list<array{int, int}>}>
 */
function cameraExifIfd(int $width, int $height): array
{
    return [
        36864 => [TIFF_UNDEFINED, '0232'],                        // ExifVersion
        36867 => [TIFF_ASCII, '2024:05:01 12:34:56'],             // DateTimeOriginal
        40960 => [TIFF_UNDEFINED, '0100'],                        // FlashpixVersion
        40961 => [TIFF_SHORT, [1]],                               // ColorSpace: sRGB
        40962 => [TIFF_LONG, [$width]],                           // PixelXDimension
        40963 => [TIFF_LONG, [$height]],                          // PixelYDimension
    ];
}

/**
 * @param  array<string, list<string>>  $datasets  "record#dataset" => values (repeatable)
 */
function encodeIptc(array $datasets): string
{
    $iptc = '';

    foreach ($datasets as $key => $values) {
        [$record, $dataset] = array_map(intval(...), explode('#', $key));

        foreach ($values as $value) {
            $iptc .= pack('CCCn', 0x1C, $record, $dataset, strlen($value)).$value;
        }
    }

    return $iptc;
}

/**
 * IPTC-IIM 4.2 record with UTF-8 text, shared by exif-iptc.jpg and exif-iptc.tiff.
 */
function iptc(): string
{
    return encodeIptc([
        '1#000' => ["\x00\x04"],                                  // ModelVersion (mandatory in record 1)
        '1#090' => ["\x1B%G"],                                    // CodedCharacterSet: UTF-8
        '2#000' => ["\x00\x04"],                                  // RecordVersion
        '2#005' => ['Zażółć gęślą jaźń'],                         // ObjectName
        '2#025' => ['katowice', 'fixture', 'żółw'],               // Keywords (repeatable)
        '2#080' => ['Jan Kowalski'],                              // By-line
        '2#116' => ['© 2024 Image Storage'],                      // CopyrightNotice
        '2#120' => ['Fixture with EXIF and IPTC metadata.'],      // Caption/Abstract
    ]);
}

/**
 * RGB 8-bit TIFF, one Deflate-compressed strip.
 *
 * @param  array<int, array{int, string|list<int>|list<array{int, int}>}>  $ifd0  extra IFD0 tags
 * @param  array<int, array{int, string|list<int>|list<array{int, int}>}>  $exifIfd
 */
function writeTiff(string $name, int $width, int $height, array $ifd0 = [], array $exifIfd = []): void
{
    $pixels = gradient($width, $height);
    $pixels->setImageFormat('rgb');
    $strip = gzcompress($pixels->getImageBlob(), 9);

    $ifd0 += [
        256 => [TIFF_LONG, [$width]],               // ImageWidth
        257 => [TIFF_LONG, [$height]],              // ImageLength
        258 => [TIFF_SHORT, [8, 8, 8]],             // BitsPerSample
        259 => [TIFF_SHORT, [8]],                   // Compression: Adobe Deflate
        262 => [TIFF_SHORT, [2]],                   // PhotometricInterpretation: RGB
        273 => [TIFF_LONG, [8]],                    // StripOffsets: payload follows the header
        277 => [TIFF_SHORT, [3]],                   // SamplesPerPixel
        278 => [TIFF_LONG, [$height]],              // RowsPerStrip
        279 => [TIFF_LONG, [strlen($strip)]],       // StripByteCounts
        282 => [TIFF_RATIONAL, [[72, 1]]],          // XResolution
        283 => [TIFF_RATIONAL, [[72, 1]]],          // YResolution
        284 => [TIFF_SHORT, [1]],                   // PlanarConfiguration: chunky
        296 => [TIFF_SHORT, [2]],                   // ResolutionUnit: inch
    ];

    writeFixture($name, encodeTiff($ifd0, $exifIfd, $strip));
}

function writeJpegWithExifAndIptc(string $name): void
{
    writeImage(gradient(640, 500), 'jpeg', $name, jpegQuality(...));
    $path = __DIR__.'/'.$name;

    $exif = 'Exif'."\0\0".encodeTiff(
        [
            271 => [TIFF_ASCII, "Caf\xE9 Optics"],                // Make: Latin-1, i.e. invalid UTF-8
            272 => [TIFF_ASCII, 'FixtureCam 500'],                // Model
            274 => [TIFF_SHORT, [6]],                             // Orientation: rotate 90° CW to display
            282 => [TIFF_RATIONAL, [[72, 1]]],                    // XResolution
            283 => [TIFF_RATIONAL, [[72, 1]]],                    // YResolution
            296 => [TIFF_SHORT, [2]],                             // ResolutionUnit: inch
            305 => [TIFF_ASCII, 'image-storage fixture generator'], // Software
            306 => [TIFF_ASCII, '2024:05:01 12:34:56'],           // DateTime
            531 => [TIFF_SHORT, [1]],                             // YCbCrPositioning: centred
        ],
        cameraExifIfd(640, 500) + [
            37121 => [TIFF_UNDEFINED, "\x01\x02\x03\x00"],        // ComponentsConfiguration: YCbCr
            37500 => [TIFF_UNDEFINED, BINARY_BYTES],              // MakerNote: opaque binary
            37510 => [TIFF_UNDEFINED, str_repeat("\0", 8).BINARY_BYTES], // UserComment, undefined charset
        ],
    );

    // Camera-style layout: APP1 directly after SOI, as the EXIF spec requires. JFIF also
    // claims that position for its APP0, so Imagick's APP0 is dropped rather than demoted.
    $jpeg = (string) file_get_contents($path);
    $rest = substr($jpeg, 2);
    if (str_starts_with($rest, "\xFF\xE0")) {
        $rest = substr($rest, 2 + unpack('n', $rest, 2)[1]);
    }
    $app1 = "\xFF\xE1".pack('n', strlen($exif) + 2).$exif;
    writeFixture($name, "\xFF\xD8".$app1.$rest);

    // iptcembed() wraps the record in a Photoshop 3.0 / 8BIM 0x0404 APP13 after APP1.
    $embedded = iptcembed(iptc(), $path);

    if (! is_string($embedded)) {
        throw new RuntimeException("Could not embed IPTC into {$name}.");
    }

    writeFixture($name, $embedded);
}

/**
 * PNG with an eXIf chunk (PNG extensions 1.5.0) inserted before the first IDAT, as required.
 * Imagick drops the EXIF profile of a stripped PNG, so the chunk is spliced in by hand.
 */
function writePngWithExif(string $name): void
{
    $png = gradient(500, 500);
    $png->setImageFormat('png');
    $bytes = $png->getImageBlob();

    // eXIf holds the bare TIFF structure, without JPEG's "Exif\0\0" prefix.
    $exif = encodeTiff(cameraIfd0(), cameraExifIfd(500, 500));
    $chunk = pack('N', strlen($exif)).'eXIf'.$exif.pack('N', crc32('eXIf'.$exif));

    $idat = strpos($bytes, 'IDAT');
    if ($idat === false) {
        throw new RuntimeException('Imagick produced a PNG without IDAT.');
    }

    // The IDAT chunk starts 4 bytes (its length field) before its type.
    writeFixture($name, substr($bytes, 0, $idat - 4).$chunk.substr($bytes, $idat - 4));
}

/**
 * Smallest valid single-page PDF, with a correct xref table.
 */
function writePdf(string $name): void
{
    $objects = [
        '<< /Type /Catalog /Pages 2 0 R >>',
        '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 500 500] /Resources << >> >>',
    ];

    $pdf = "%PDF-1.4\n";
    $offsets = [];

    foreach ($objects as $index => $object) {
        $offsets[] = strlen($pdf);
        $pdf .= ($index + 1)." 0 obj\n{$object}\nendobj\n";
    }

    $xref = strlen($pdf);
    $pdf .= 'xref'."\n".'0 '.(count($objects) + 1)."\n".'0000000000 65535 f '."\n";

    foreach ($offsets as $offset) {
        $pdf .= sprintf('%010d 00000 n ', $offset)."\n";
    }

    $pdf .= 'trailer'."\n".'<< /Size '.(count($objects) + 1).' /Root 1 0 R >>'."\n"
        .'startxref'."\n".$xref."\n".'%%EOF'."\n";

    writeFixture($name, $pdf);
}

$square = gradient(500, 500);

writeImage($square, 'jpeg', 'valid.jpg', jpegQuality(...));
writeImage($square, 'png', 'valid.png');
writeImage($square, 'webp', 'valid.webp', static fn (Imagick $i): bool => $i->setImageCompressionQuality(80));
// 8-bit indexed, uncompressed (BI_RGB): the most widely decodable BMP variant that is still
// small; 24-bit would be 750 KB for no extra coverage.
writeImage($square, 'bmp3', 'valid.bmp', static function (Imagick $i): void {
    $i->quantizeImage(256, Imagick::COLORSPACE_SRGB, 0, false, false);
    $i->setImageType(Imagick::IMGTYPE_PALETTE);
    // Writer-level option: the BMP coder ignores setImageCompression() and picks RLE8 for
    // palette images.
    $i->setCompression(Imagick::COMPRESSION_NO);
});
writeImage($square, 'gif', 'not-allowed.gif');

writeImage(gradient(499, 500), 'jpeg', 'too-narrow.jpg', jpegQuality(...));
writeImage(gradient(500, 499), 'jpeg', 'too-short.jpg', jpegQuality(...));

writeJpegWithExifAndIptc('exif-iptc.jpg');
writePngWithExif('exif.png');
// Imagick writes a spec-correct WebP here: VP8X with the EXIF flag and a bare-TIFF EXIF chunk.
writeImage($square, 'webp', 'exif.webp', static function (Imagick $i): void {
    $i->setImageCompressionQuality(80);
    $i->setImageProfile('exif', 'Exif'."\0\0".encodeTiff(cameraIfd0(), cameraExifIfd(500, 500)));
});

writeTiff('valid.tiff', 500, 500);
writeTiff(
    'exif-iptc.tiff',
    500,
    500,
    cameraIfd0() + [
        33723 => [TIFF_UNDEFINED, iptc()],                        // IPTC-NAA
    ],
    cameraExifIfd(500, 500),
);

// Two pages, the second one larger and flat-coloured: getimagesize() (and so validation) only
// sees the first page, while Imagick decodes every page unless told otherwise.
$secondPage = new Imagick;
$secondPage->newImage(1200, 300, '#2a9d8f');
$secondPage->setImageDepth(8);

$document = new Imagick;
$document->addImage($square);
$document->addImage($secondPage);
$document->setFormat('tiff');
foreach ($document as $page) {
    $page->setImageFormat('tiff');
    $page->setImageCompression(Imagick::COMPRESSION_ZIP);
}
// As with BMP, the TIFF coder only honours the writer-level setting for multi-image output.
$document->setCompression(Imagick::COMPRESSION_ZIP);
writeFixture('multipage.tiff', $document->getImagesBlob());

writePdf('not-an-image.pdf');

echo 'Fixtures written to '.__DIR__.PHP_EOL;
