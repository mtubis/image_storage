<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

// The SPA is served from another origin (FRONTEND_URL, set in phpunit.xml) and may only
// talk to the API through CORS; no other origin is allowed.

uses(RefreshDatabase::class);

const CORS_TEST_ORIGIN = 'http://frontend.test';

/**
 * @return TestResponse<Response>
 */
function cors_preflight(string $uri, string $origin, string $method, string $headers = ''): TestResponse
{
    return test()->call('OPTIONS', $uri, server: array_filter([
        'HTTP_ORIGIN' => $origin,
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => $method,
        'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => $headers,
    ]));
}

it('allows a preflight from the frontend origin', function (): void {
    $uri = '/api/v1/images/'.Str::lower((string) Str::ulid());

    cors_preflight($uri, CORS_TEST_ORIGIN, 'DELETE', 'accept')
        ->assertNoContent()
        ->assertHeader('Access-Control-Allow-Origin', CORS_TEST_ORIGIN)
        ->assertHeader('Access-Control-Allow-Methods', 'GET, POST, DELETE')
        ->assertHeader('Access-Control-Allow-Headers', 'accept, content-type')
        ->assertHeader('Access-Control-Max-Age', '7200');
});

it('does not allow a preflight from another origin', function (): void {
    $response = cors_preflight('/api/v1/images', 'http://evil.test', 'POST');

    // With a single allowed origin, the header always names it; the browser then rejects
    // the mismatch. What matters is that the foreign origin (or `*`) is never echoed.
    expect($response->headers->get('Access-Control-Allow-Origin'))->toBe(CORS_TEST_ORIGIN);
});

it('allows no origin at all when FRONTEND_URL is unset', function (): void {
    config(['cors.allowed_origins' => []]);

    cors_preflight('/api/v1/images', CORS_TEST_ORIGIN, 'POST')
        ->assertHeaderMissing('Access-Control-Allow-Origin');
});

it('does not allow methods or headers the API does not use', function (): void {
    $response = cors_preflight('/api/v1/images', CORS_TEST_ORIGIN, 'PUT', 'authorization');

    expect($response->headers->get('Access-Control-Allow-Methods'))->not->toContain('PUT')
        ->and($response->headers->get('Access-Control-Allow-Headers'))->not->toContain('authorization');
});

it('exposes Content-Disposition, so the frontend can read the download filename', function (): void {
    fake_image_storage();
    $image = stored_image('valid.jpg');

    $this->get("/api/v1/images/{$image->id}/download", ['Origin' => CORS_TEST_ORIGIN])
        ->assertOk()
        ->assertHeader('Access-Control-Allow-Origin', CORS_TEST_ORIGIN)
        ->assertHeader('Access-Control-Expose-Headers', 'Content-Disposition');
});

it('adds CORS headers to error responses, so the frontend can read them', function (): void {
    $this->post('/api/v1/images', [], ['Origin' => CORS_TEST_ORIGIN])
        ->assertUnprocessable()
        ->assertHeader('Access-Control-Allow-Origin', CORS_TEST_ORIGIN);

    $this->delete('/api/v1/images/not-an-id', [], ['Origin' => CORS_TEST_ORIGIN])
        ->assertNotFound()
        ->assertHeader('Access-Control-Allow-Origin', CORS_TEST_ORIGIN);
});

it('applies CORS to the API only', function (): void {
    $this->get('/up', ['Origin' => CORS_TEST_ORIGIN])
        ->assertOk()
        ->assertHeaderMissing('Access-Control-Allow-Origin');
});
