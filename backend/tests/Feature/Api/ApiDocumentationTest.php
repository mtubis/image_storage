<?php

declare(strict_types=1);

use App\Models\Image;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

// The OpenAPI document Scramble generates from the code (/docs/api.json) and its UI (/docs/api).
// Scramble infers most of it, and inference can silently go wrong, so the document is checked
// against real responses of the API rather than against a copy of itself.

uses(RefreshDatabase::class);

beforeEach(function (): void {
    fake_image_storage();
    Queue::fake();
    // A document stored by `scramble:cache` would be served as is, however old.
    config(['scramble.cache.store' => null]);
});

/**
 * The generated document, with every local "$ref" replaced by what it points to.
 *
 * @return array<string, mixed>
 */
function api_spec(): array
{
    /** @var array<string, mixed> $spec */
    $spec = test()->getJson('/docs/api.json')->assertOk()->json();

    /** @var array<string, mixed> */
    return resolve_refs($spec, $spec);
}

/**
 * @param  array<string, mixed>  $spec
 */
function resolve_refs(mixed $node, array $spec): mixed
{
    if (! is_array($node)) {
        return $node;
    }

    if (isset($node['$ref']) && is_string($node['$ref'])) {
        $target = $spec;

        foreach (explode('/', substr($node['$ref'], 2)) as $segment) {
            $target = $target[$segment] ?? throw new RuntimeException("Unresolvable reference [{$node['$ref']}].");
        }

        return resolve_refs($target, $spec);
    }

    return array_map(fn (mixed $child): mixed => resolve_refs($child, $spec), $node);
}

/**
 * @param  array<string, mixed>  $spec
 * @return array<string, mixed>
 */
function operation(array $spec, string $method, string $path): array
{
    return $spec['paths'][$path][$method] ?? throw new RuntimeException("Undocumented operation [{$method} {$path}].");
}

/**
 * @param  array<string, mixed>  $spec
 * @return array<string, mixed>
 */
function json_response_schema(array $spec, string $method, string $path, int $status): array
{
    return operation($spec, $method, $path)['responses'][$status]['content']['application/json']['schema'];
}

/**
 * Asserts that a decoded JSON value has exactly the documented shape: every property it has is
 * documented and required, every documented property is present, and every value has a
 * documented type. The API always sends all keys, so "optional" would itself be inaccurate.
 *
 * @param  array<string, mixed>  $schema
 */
function expect_matches_schema(mixed $value, array $schema, string $path = '$'): void
{
    $types = (array) ($schema['type'] ?? []);
    $type = match (true) {
        $value === null => 'null',
        is_int($value) => 'integer',
        is_float($value) => 'number',
        is_string($value) => 'string',
        is_bool($value) => 'boolean',
        is_array($value) && array_is_list($value) && ($value !== [] || in_array('array', $types, true)) => 'array',
        default => 'object',
    };

    // In JSON Schema, "number" includes integers.
    $documented = in_array($type, $types, true) || ($type === 'integer' && in_array('number', $types, true));
    expect($documented)->toBeTrue("{$path} is {$type}, documented as ".implode('|', $types).'.');

    // A map with arbitrary keys, such as the "errors" of a validation error.
    if ($type === 'object' && is_array($schema['additionalProperties'] ?? null)) {
        /** @var array<string, mixed> $value */
        foreach ($value as $key => $child) {
            expect_matches_schema($child, $schema['additionalProperties'], "{$path}.{$key}");
        }

        return;
    }

    if ($type === 'object') {
        /** @var array<string, mixed> $value */
        /** @var array<string, array<string, mixed>> $properties */
        $properties = $schema['properties'] ?? [];

        expect(array_keys($value))->toEqualCanonicalizing(array_keys($properties), "Properties of {$path} differ from the documentation.")
            ->and($schema['required'] ?? [])->toEqualCanonicalizing(array_keys($properties), "Not every property of {$path} is documented as required.");

        foreach ($value as $key => $child) {
            expect_matches_schema($child, $properties[$key], "{$path}.{$key}");
        }
    }

    if ($type === 'array') {
        /** @var list<mixed> $value */
        /** @var array<string, mixed> $items */
        $items = $schema['items'];

        foreach ($value as $index => $child) {
            expect_matches_schema($child, $items, "{$path}[{$index}]");
        }
    }
}

/**
 * Every property name anywhere in a schema tree.
 *
 * @return list<string>
 */
function property_names(mixed $node): array
{
    if (! is_array($node)) {
        return [];
    }

    $names = isset($node['properties']) && is_array($node['properties']) ? array_map(strval(...), array_keys($node['properties'])) : [];

    foreach ($node as $child) {
        $names = [...$names, ...property_names($child)];
    }

    return $names;
}

/**
 * @return list<string>
 */
function allowed_mime_types(): array
{
    return array_map(strval(...), array_keys(config()->array('images.allowed_types')));
}

it('renders the documentation page', function (): void {
    $this->get('/docs/api')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/html; charset=utf-8')
        ->assertSee('<elements-api', escape: false);
});

// The API has no authentication, so the document reveals nothing the API itself doesn't.
it('is available in every environment', function (string $environment): void {
    app()->detectEnvironment(fn (): string => $environment);

    $this->get('/docs/api')->assertOk();
    $this->getJson('/docs/api.json')->assertOk();
})->with(['local', 'testing', 'production']);

it('documents exactly the endpoints of the API, grouped under one tag', function (): void {
    $spec = api_spec();

    $operations = [];

    foreach ($spec['paths'] as $path => $methods) {
        foreach ($methods as $method => $operation) {
            $operations[] = strtoupper($method).' '.$path;

            expect($operation['tags'])->toBe(['Images'])
                ->and($operation['summary'] ?? '')->not->toBe('', "{$method} {$path} has no summary.");
        }
    }

    expect($operations)->toEqualCanonicalizing([
        'GET /v1/images',
        'POST /v1/images',
        'DELETE /v1/images/{image}',
        'GET /v1/images/{image}/download',
    ])->and($spec['servers'][0]['url'])->toBe(url('/api'));
});

it('documents the listing as it is sent, pagination included', function (): void {
    // Eleven images: the first page then has a next cursor, the second a previous one.
    Image::factory()->count(10)->create();
    Image::factory()->withTemperature()->create();
    $schema = json_response_schema(api_spec(), 'get', '/v1/images', 200);

    $firstPage = $this->getJson('/api/v1/images')->assertOk()->json();
    $secondPage = $this->getJson('/api/v1/images?cursor='.urlencode($firstPage['meta']['next_cursor']))->assertOk()->json();

    expect_matches_schema($firstPage, $schema);
    expect_matches_schema($secondPage, $schema);
});

it('documents the cursor query parameter of the listing', function (): void {
    $operation = operation(api_spec(), 'get', '/v1/images');

    expect($operation['parameters'])->toHaveCount(1)
        ->and($operation['parameters'][0])->toMatchArray(['name' => 'cursor', 'in' => 'query'])
        ->and($operation['parameters'][0]['description'])->toContain('meta.next_cursor');
});

it('documents the stored image as it is sent', function (): void {
    $schema = json_response_schema(api_spec(), 'post', '/v1/images', 201);

    expect_matches_schema(post_image()->assertCreated()->json(), $schema);
});

it('documents the upload as multipart with its constraints taken from config', function (): void {
    $body = operation(api_spec(), 'post', '/v1/images')['requestBody'];
    $schema = $body['content']['multipart/form-data']['schema'];
    $file = $schema['properties']['file'];

    expect($body['required'])->toBeTrue()
        ->and(array_keys($body['content']))->toBe(['multipart/form-data'])
        ->and($schema['required'])->toEqualCanonicalizing(['file', 'uploader_name', 'uploader_email'])
        ->and($file['format'])->toBe('binary')
        ->and($file['description'])->toContain(
            number_format(config()->integer('images.max_size_kb') * 1024).' bytes',
            config()->integer('images.min_width').'×'.config()->integer('images.min_height'),
            config()->integer('images.max_width').'×'.config()->integer('images.max_height'),
        )
        ->and($schema['properties']['uploader_name']['maxLength'])->toBe(100)
        ->and($schema['properties']['uploader_email'])->toMatchArray(['format' => 'email', 'maxLength' => 255]);

    /** @var array<string, list<string>> $types */
    $types = config()->array('images.allowed_types');

    foreach (array_merge(...array_values($types)) as $extension) {
        expect($file['description'])->toContain($extension);
    }
});

it('never documents the uploader e-mail as part of a response', function (): void {
    $spec = api_spec();

    foreach ($spec['paths'] as $methods) {
        foreach ($methods as $operation) {
            expect(property_names($operation['responses']))->not->toContain('uploader_email');
        }
    }
});

it('documents the download as the original file, with the headers it is sent with', function (): void {
    $response = operation(api_spec(), 'get', '/v1/images/{image}/download')['responses'][200];

    expect(array_keys($response['content']))->toEqualCanonicalizing(allowed_mime_types());

    foreach ($response['content'] as $content) {
        expect($content['schema'])->toMatchArray(['type' => 'string', 'format' => 'binary']);
    }

    expect(array_keys($response['headers']))->toEqualCanonicalizing([
        'Content-Disposition',
        'Content-Length',
        'X-Content-Type-Options',
        'Content-Security-Policy',
    ]);

    $image = stored_image('valid.png', ['mime_type' => 'image/png', 'extension' => 'png', 'original_name' => 'photo.png']);
    $download = $this->get("/api/v1/images/{$image->id}/download")->assertOk();

    foreach ($response['headers'] as $name => $header) {
        expect($header['required'])->toBeTrue()
            ->and($download->headers->has($name))->toBeTrue("Documented header {$name} is not sent.");

        if (isset($header['schema']['enum'])) {
            expect($header['schema']['enum'])->toContain($download->headers->get($name));
        }
    }

    expect($response['content'])->toHaveKey((string) $download->headers->get('Content-Type'))
        ->and($download->headers->get('Content-Disposition'))->toStartWith('attachment;')
        ->and($download->headers->get('Content-Length'))->toBe((string) strlen(fixture_contents('valid.png')));
});

it('describes every field of an image', function (): void {
    $schema = json_response_schema(api_spec(), 'post', '/v1/images', 201)['properties']['data'];

    foreach ($schema['properties'] as $name => $property) {
        expect($property['description'] ?? '')->not->toBe('', "{$name} has no description.");
    }
});

it('documents the error responses as they are sent', function (): void {
    $spec = api_spec();

    expect_matches_schema(
        $this->getJson('/api/v1/images/01k0000000000000000000000/download')->assertNotFound()->json(),
        json_response_schema($spec, 'get', '/v1/images/{image}/download', 404),
    );
    expect_matches_schema(
        $this->deleteJson('/api/v1/images/01k0000000000000000000000')->assertNotFound()->json(),
        json_response_schema($spec, 'delete', '/v1/images/{image}', 404),
    );
    expect_matches_schema(
        post_image(['uploader_name' => ''])->assertUnprocessable()->json(),
        json_response_schema($spec, 'post', '/v1/images', 422),
    );
    // nginx refuses the body first in the real stack (docker/nginx, same JSON body); here PHP's
    // limit is simulated, which Laravel answers the same way. Without debug output, as in production.
    config(['app.debug' => false]);
    expect_matches_schema(
        $this->call('POST', '/api/v1/images', server: ['CONTENT_LENGTH' => (string) (11 * 1024 * 1024), 'HTTP_ACCEPT' => 'application/json'])
            ->assertStatus(413)->json(),
        json_response_schema($spec, 'post', '/v1/images', 413),
    );
    expect_matches_schema(
        $this->getJson('/api/v1/images?cursor=invalid')->assertUnprocessable()->json(),
        json_response_schema($spec, 'get', '/v1/images', 422),
    );
});

it('documents the deletion as an empty 204', function (): void {
    $responses = operation(api_spec(), 'delete', '/v1/images/{image}')['responses'];

    expect(array_keys($responses))->toEqualCanonicalizing([204, 404])
        ->and($responses[204])->not->toHaveKey('content');
});
