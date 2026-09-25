<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\Images\StoreImage;
use App\Data\StoreImageData;
use Illuminate\Database\Seeder;
use Imagick;

/**
 * Images for the E2E list scenarios (infinite scroll, download, delete).
 *
 * Stored through the upload action, not the model factory: the list then shows real
 * thumbnails and the download returns a real file. Generated at runtime, so the backend
 * never depends on the test fixtures.
 */
final class E2eImageSeeder extends Seeder
{
    // More than one page (config('images.page_size')), so scrolling has a page to load.
    public const int COUNT = 15;

    public function run(StoreImage $storeImage): void
    {
        for ($number = 1; $number <= self::COUNT; $number++) {
            $storeImage->handle(new StoreImageData(
                contents: $this->jpeg($number),
                originalName: sprintf('seed-%02d.jpg', $number),
                mimeType: 'image/jpeg',
                extension: 'jpg',
                uploaderName: 'E2E Seeder',
                uploaderEmail: 'e2e-seeder@example.com',
            ));
        }
    }

    // Distinct colours tell the cards apart on screenshots; every other image is portrait,
    // which a square thumbnail box has to contain as well.
    private function jpeg(int $number): string
    {
        [$width, $height] = $number % 2 === 0 ? [600, 800] : [800, 600];

        $image = new Imagick;

        try {
            $image->newImage($width, $height, sprintf('hsl(%d, 60%%, 60%%)', $number * 24 % 360), 'jpeg');

            return $image->getImageBlob();
        } finally {
            $image->clear();
        }
    }
}
