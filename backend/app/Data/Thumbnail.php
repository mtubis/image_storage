<?php

declare(strict_types=1);

namespace App\Data;

/**
 * A rendered thumbnail together with the size of the original it was rendered from.
 *
 * The source size is reported as displayed: its stored size, with the edges swapped when
 * the decoder that oriented the thumbnail rotated it by 90°. So the stored dimensions can
 * never disagree with the thumbnail.
 */
final readonly class Thumbnail
{
    /**
     * @param  string  $contents  WebP bytes
     * @param  int  $sourceWidth  displayed width of the original, in pixels
     * @param  int  $sourceHeight  displayed height of the original, in pixels
     */
    public function __construct(
        public string $contents,
        public int $sourceWidth,
        public int $sourceHeight,
    ) {}
}
