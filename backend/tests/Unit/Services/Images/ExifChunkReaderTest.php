<?php

declare(strict_types=1);

use App\Services\Images\ExifChunkReader;

const TIFF_STUB = "II*\x00\x08\x00\x00\x00\x00\x00";

function png_chunk(string $type, string $data): string
{
    return pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
}

/**
 * @param  list<string>  $chunks
 */
function png_of_chunks(array $chunks): string
{
    return "\x89PNG\r\n\x1A\n".implode('', $chunks);
}

function riff_chunk(string $type, string $data): string
{
    return $type.pack('V', strlen($data)).$data.(strlen($data) % 2 === 1 ? "\x00" : '');
}

/**
 * @param  list<string>  $chunks
 */
function webp_of_chunks(array $chunks): string
{
    $body = 'WEBP'.implode('', $chunks);

    return 'RIFF'.pack('V', strlen($body)).$body;
}

describe('PNG', function (): void {
    it('finds the eXIf chunk wherever it is', function (array $chunks): void {
        expect(new ExifChunkReader()->fromPng(png_of_chunks($chunks)))->toBe(TIFF_STUB);
    })->with([
        'before IDAT, as the spec asks' => [[png_chunk('IHDR', str_repeat("\x00", 13)), png_chunk('eXIf', TIFF_STUB), png_chunk('IDAT', 'x'), png_chunk('IEND', '')]],
        'after IDAT' => [[png_chunk('IHDR', str_repeat("\x00", 13)), png_chunk('IDAT', 'x'), png_chunk('eXIf', TIFF_STUB), png_chunk('IEND', '')]],
        'after a zero-length chunk' => [[png_chunk('sRGB', ''), png_chunk('eXIf', TIFF_STUB)]],
    ]);

    it('strips a JPEG APP1 identifier some writers leave in the payload', function (): void {
        expect(new ExifChunkReader()->fromPng(png_of_chunks([png_chunk('eXIf', "Exif\x00\x00".TIFF_STUB)])))
            ->toBe(TIFF_STUB);
    });

    it('returns null when there is no EXIF or the data is damaged', function (string $png): void {
        expect(new ExifChunkReader()->fromPng($png))->toBeNull();
    })->with([
        'no eXIf chunk' => png_of_chunks([png_chunk('IHDR', str_repeat("\x00", 13)), png_chunk('IEND', '')]),
        'wrong signature' => 'GIF89a'.png_chunk('eXIf', TIFF_STUB),
        'chunk length beyond the end' => png_of_chunks([pack('N', 0x7FFFFFFF).'eXIf'.TIFF_STUB]),
        'truncated chunk header' => png_of_chunks(["\x00\x00\x00"]),
        'signature only' => png_of_chunks([]),
    ]);
});

describe('WebP', function (): void {
    it('finds the EXIF chunk, honouring the padding of odd-sized chunks', function (): void {
        $webp = webp_of_chunks([riff_chunk('VP8X', str_repeat("\x00", 10)), riff_chunk('ICCP', 'odd'), riff_chunk('EXIF', TIFF_STUB)]);

        expect(new ExifChunkReader()->fromWebp($webp))->toBe(TIFF_STUB);
    });

    it('strips a JPEG APP1 identifier some writers leave in the payload', function (): void {
        expect(new ExifChunkReader()->fromWebp(webp_of_chunks([riff_chunk('EXIF', "Exif\x00\x00".TIFF_STUB)])))
            ->toBe(TIFF_STUB);
    });

    it('returns null when there is no EXIF or the data is damaged', function (string $webp): void {
        expect(new ExifChunkReader()->fromWebp($webp))->toBeNull();
    })->with([
        'no EXIF chunk' => webp_of_chunks([riff_chunk('VP8X', str_repeat("\x00", 10))]),
        'not a WebP RIFF' => 'RIFF'.pack('V', 4).'WAVE'.riff_chunk('EXIF', TIFF_STUB),
        'chunk size beyond the end' => webp_of_chunks(['EXIF'.pack('V', 0x7FFFFFF0).TIFF_STUB]),
        'too short for a header' => 'RIFF',
        'only a zero-length XMP chunk' => webp_of_chunks([riff_chunk('XMP ', '')]),
    ]);

    it('returns an empty payload for a zero-length EXIF chunk', function (): void {
        expect(new ExifChunkReader()->fromWebp(webp_of_chunks([riff_chunk('EXIF', '')])))->toBe('');
    });
});
