<?php

declare(strict_types=1);

// Pins the contract of the committed fixtures (see tests/Fixtures/README.md): validation,
// thumbnail and metadata tests rely on these exact properties, so a regenerated or replaced
// fixture that silently drifts (other dimensions, lost metadata) must fail here first.

const FIXTURE_BINARY_BYTES = "\x00\x01\x02\xFF\xFE\x80\x81\x7F";

/**
 * @return array<string, list<string>>
 */
function fixture_iptc(string $app13OrNaa): array
{
    $iptc = iptcparse($app13OrNaa);

    return is_array($iptc) ? $iptc : [];
}

describe('format and dimensions', function (): void {
    it('has the expected format and dimensions', function (string $name, string $mime, int $width, int $height): void {
        $path = fixture_path($name);
        $size = getimagesize($path);

        expect(mime_content_type($path))->toBe($mime)
            ->and($size)->not->toBeFalse()
            ->and([$size[0] ?? null, $size[1] ?? null])->toBe([$width, $height])
            ->and(filesize($path))->toBeLessThan(config('images.max_size_kb') * 1024);
    })->with([
        'valid.jpg' => ['valid.jpg', 'image/jpeg', 500, 500],
        'valid.png' => ['valid.png', 'image/png', 500, 500],
        'valid.webp' => ['valid.webp', 'image/webp', 500, 500],
        'valid.tiff' => ['valid.tiff', 'image/tiff', 500, 500],
        'valid.bmp' => ['valid.bmp', 'image/bmp', 500, 500],
        'too-narrow.jpg' => ['too-narrow.jpg', 'image/jpeg', 499, 500],
        'too-short.jpg' => ['too-short.jpg', 'image/jpeg', 500, 499],
        // Stored landscape; Orientation=6 means it is displayed as 500×640.
        'exif-iptc.jpg' => ['exif-iptc.jpg', 'image/jpeg', 640, 500],
        'exif-iptc.tiff' => ['exif-iptc.tiff', 'image/tiff', 500, 500],
        'exif.png' => ['exif.png', 'image/png', 500, 500],
        'exif.webp' => ['exif.webp', 'image/webp', 500, 500],
        // getimagesize() reports only the first page; see the multipage.tiff tests below.
        'multipage.tiff' => ['multipage.tiff', 'image/tiff', 500, 500],
        'not-allowed.gif' => ['not-allowed.gif', 'image/gif', 500, 500],
    ]);

    it('has a real PDF as a non-image fixture', function (): void {
        $path = fixture_path('not-an-image.pdf');

        expect(mime_content_type($path))->toBe('application/pdf')
            ->and(@getimagesize($path))->toBeFalse();
    });

    it('decodes every supported format with Imagick', function (string $name): void {
        $image = new Imagick(fixture_path($name));

        expect($image->getImageWidth())->toBeGreaterThanOrEqual(500)
            ->and($image->getImageHeight())->toBeGreaterThanOrEqual(500);
    })->with([
        'valid.jpg', 'valid.png', 'valid.webp', 'valid.tiff', 'valid.bmp',
        'exif-iptc.jpg', 'exif-iptc.tiff', 'exif.png', 'exif.webp',
    ]);

    // RLE-compressed BMPs are poorly supported by decoders; keep the common BI_RGB variant.
    it('has an 8-bit uncompressed BMP', function (): void {
        $header = unpack('vbpp/Vcompression', (string) file_get_contents(fixture_path('valid.bmp')), 28);

        expect($header)->toBe(['bpp' => 8, 'compression' => 0]);
    });
});

describe('plain fixtures', function (): void {
    // getimagesize() only reports APPn segments for JPEG; Imagick sees profiles in every format.
    it('carries no metadata profile', function (string $name): void {
        expect(new Imagick(fixture_path($name))->getImageProfiles('*', false))->toBe([]);
    })->with(['valid.jpg', 'valid.png', 'valid.webp', 'valid.tiff', 'valid.bmp']);

    it('has no EXIF or IPTC segment in valid.jpg', function (): void {
        getimagesize(fixture_path('valid.jpg'), $info);

        expect($info)->not->toHaveKeys(['APP1', 'APP13']);
    });

    it('carries only structural TIFF tags in valid.tiff', function (): void {
        $exif = exif_read_data(fixture_path('valid.tiff'));

        expect($exif)->toBeArray()
            ->and($exif)->toHaveKeys(['ImageWidth', 'BitsPerSample', 'StripOffsets'])
            ->and($exif)->not->toHaveKeys(['Make', 'DateTimeOriginal', 'IPTC/NAA']);
    });
});

describe('exif-iptc.jpg', function (): void {
    it('has a camera-style marker layout: SOI, APP1 (Exif), APP13, no JFIF', function (): void {
        $bytes = (string) file_get_contents(fixture_path('exif-iptc.jpg'));
        $app1Length = unpack('n', $bytes, 4)[1];

        expect(substr($bytes, 0, 4))->toBe("\xFF\xD8\xFF\xE1")
            ->and(substr($bytes, 6, 6))->toBe("Exif\0\0")
            ->and(substr($bytes, 4 + $app1Length, 2))->toBe("\xFF\xED")
            ->and($bytes)->not->toContain('JFIF');
    });

    it('carries EXIF, including non-UTF-8 and binary values', function (): void {
        $exif = exif_read_data(fixture_path('exif-iptc.jpg'), null, true);

        expect($exif)->toBeArray()
            ->and($exif['IFD0']['Model'] ?? null)->toBe('FixtureCam 500')
            ->and($exif['IFD0']['Orientation'] ?? null)->toBe(6)
            ->and($exif['EXIF']['DateTimeOriginal'] ?? null)->toBe('2024:05:01 12:34:56')
            // ext-exif names PixelX/YDimension after their old Exif 2.1 names.
            ->and($exif['EXIF']['ExifImageWidth'] ?? null)->toBe(640)
            ->and($exif['EXIF']['ExifImageLength'] ?? null)->toBe(500)
            // Latin-1 "Café": the sanitizer in step 1.5 must cope with invalid UTF-8.
            ->and($exif['IFD0']['Make'] ?? null)->toBe("Caf\xE9 Optics")
            ->and(mb_check_encoding($exif['IFD0']['Make'] ?? '', 'UTF-8'))->toBeFalse()
            // Undefined charset header + raw bytes, returned verbatim.
            ->and($exif['EXIF']['UserComment'] ?? null)->toBe(str_repeat("\0", 8).FIXTURE_BINARY_BYTES)
            ->and($exif['COMPUTED']['UserCommentEncoding'] ?? null)->toBe('UNDEFINED')
            // ext-exif only decodes maker notes of known vendors; others come back as null.
            ->and($exif['EXIF'] ?? [])->toHaveKey('MakerNote')
            ->and($exif['EXIF']['MakerNote'])->toBeNull()
            ->and(json_encode($exif))->toBeFalse()
            ->and(json_last_error())->toBe(JSON_ERROR_UTF8);
    });

    it('carries UTF-8 IPTC in APP13', function (): void {
        getimagesize(fixture_path('exif-iptc.jpg'), $info);
        $iptc = fixture_iptc($info['APP13'] ?? '');

        expect($iptc['1#090'] ?? null)->toBe(["\x1B%G"])
            ->and($iptc['2#005'] ?? null)->toBe(['Zażółć gęślą jaźń'])
            ->and($iptc['2#025'] ?? null)->toBe(['katowice', 'fixture', 'żółw'])
            ->and($iptc['2#080'] ?? null)->toBe(['Jan Kowalski'])
            ->and($iptc['2#116'] ?? null)->toBe(['© 2024 Image Storage']);
    });
});

describe('exif-iptc.tiff', function (): void {
    it('carries EXIF in both IFD0 and the Exif sub-IFD', function (): void {
        $exif = exif_read_data(fixture_path('exif-iptc.tiff'), null, true);

        expect($exif)->toBeArray()
            ->and($exif['IFD0']['Make'] ?? null)->toBe('FixtureCam')
            ->and($exif['IFD0']['Model'] ?? null)->toBe('FixtureCam 500')
            ->and($exif['IFD0']['Artist'] ?? null)->toBe('Jan Kowalski')
            ->and($exif['IFD0']['Software'] ?? null)->toBe('image-storage fixture generator')
            ->and($exif['IFD0']['DateTime'] ?? null)->toBe('2024:05:01 12:34:56')
            ->and($exif['EXIF']['ExifVersion'] ?? null)->toBe('0232')
            ->and($exif['EXIF']['DateTimeOriginal'] ?? null)->toBe('2024:05:01 12:34:56');
    });

    // getimagesize() has no APP13 for TIFF; the IPTC-NAA tag (33723) is the only route.
    it('carries IPTC in the IPTC-NAA tag', function (): void {
        getimagesize(fixture_path('exif-iptc.tiff'), $info);
        $exif = exif_read_data(fixture_path('exif-iptc.tiff'));
        $iptc = fixture_iptc(is_array($exif) && is_string($exif['IPTC/NAA'] ?? null) ? $exif['IPTC/NAA'] : '');

        expect($info)->toBe([])
            ->and($iptc['2#005'] ?? null)->toBe(['Zażółć gęślą jaźń'])
            ->and($iptc['2#025'] ?? null)->toBe(['katowice', 'fixture', 'żółw'])
            ->and(new Imagick(fixture_path('exif-iptc.tiff'))->getImageProfiles('*', false))->toBe(['iptc']);
    });
});

describe('multipage.tiff', function (): void {
    it('has a larger second page that only Imagick sees', function (): void {
        // Iterating Imagick yields the same object with a moved cursor, so read sizes in the loop.
        $pages = [];
        foreach (new Imagick(fixture_path('multipage.tiff')) as $page) {
            $pages[] = [$page->getImageWidth(), $page->getImageHeight()];
        }

        expect($pages)->toBe([[500, 500], [1200, 300]]);
    });
});

describe('EXIF in PNG and WebP', function (): void {
    // Real-world files carry EXIF here too, but ext-exif cannot read these containers.
    it('is readable only through Imagick', function (string $name): void {
        $image = new Imagick(fixture_path($name));

        expect(@exif_read_data(fixture_path($name)))->toBeFalse()
            ->and($image->getImageProfiles('*', false))->toBe(['exif'])
            ->and($image->getImageProperty('exif:Make'))->toBe('FixtureCam')
            ->and($image->getImageProperty('exif:DateTimeOriginal'))->toBe('2024:05:01 12:34:56');
    })->with(['exif.png', 'exif.webp']);

    it('puts eXIf before the first IDAT in exif.png', function (): void {
        $bytes = (string) file_get_contents(fixture_path('exif.png'));

        expect(strpos($bytes, 'eXIf'))->toBeInt()->toBeLessThan(strpos($bytes, 'IDAT'));
    });
});

it('is not supported by exif_read_data for non-JPEG/TIFF formats', function (string $name): void {
    expect(@exif_read_data(fixture_path($name)))->toBeFalse();
})->with(['valid.png', 'valid.webp', 'valid.bmp', 'not-allowed.gif']);

it('throws for an unknown fixture', function (): void {
    fixture_path('missing.jpg');
})->throws(RuntimeException::class, 'missing.jpg');
