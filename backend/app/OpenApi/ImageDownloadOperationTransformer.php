<?php

declare(strict_types=1);

namespace App\OpenApi;

use Dedoc\Scramble\Contracts\OperationTransformer;
use Dedoc\Scramble\Support\Generator\Header;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Reference;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Dedoc\Scramble\Support\Generator\Types\Type;
use Dedoc\Scramble\Support\RouteInfo;

/**
 * Scramble can only document a streamed response as an octet stream sent in chunks. The
 * download sends the stored type of the image, as an attachment, with its size known up front.
 */
final readonly class ImageDownloadOperationTransformer implements OperationTransformer
{
    public function handle(Operation $operation, RouteInfo $routeInfo): void
    {
        // By route name: the Laravel arch preset keeps controllers out of other layers.
        if ($routeInfo->route->getName() !== 'v1.images.download') {
            return;
        }

        $response = new Response(200)
            ->setDescription('The original file, byte for byte, with its EXIF and IPTC metadata.')
            ->addHeader('Content-Disposition', new Header(
                description: 'Always an attachment, named by the original file name (RFC 6266, with an ASCII fallback).',
                required: true,
                schema: $this->schema(new StringType),
            ))
            ->addHeader('Content-Length', new Header(
                description: 'Size of the file in bytes.',
                required: true,
                schema: $this->schema(new IntegerType),
            ))
            ->addHeader('X-Content-Type-Options', new Header(
                description: 'Always `nosniff`: the file is never interpreted as another type.',
                required: true,
                schema: $this->schema((new StringType)->enum(['nosniff'])),
            ))
            ->addHeader('Content-Security-Policy', new Header(
                description: 'Blocks any active content, should the file ever be rendered inline.',
                required: true,
                schema: $this->schema((new StringType)->enum(["default-src 'none'; sandbox"])),
            ));

        foreach (array_keys(config()->array('images.allowed_types')) as $mimeType) {
            $response->setContent((string) $mimeType, $this->schema((new StringType)->format('binary')));
        }

        $operation->responses = [
            $response,
            ...array_filter(
                $operation->responses ?? [],
                static fn (Response|Reference $existing): bool => ! $existing instanceof Response || $existing->code !== 200,
            ),
        ];
    }

    private function schema(Type $type): Schema
    {
        // Scramble's factory declares no return type.
        /** @var Schema $schema */
        $schema = Schema::fromType($type);

        return $schema;
    }
}
