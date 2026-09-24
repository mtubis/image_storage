<?php

declare(strict_types=1);

use App\Contracts\WeatherProvider;
use App\Exceptions\Weather\TemperatureNotAvailable;
use App\Exceptions\Weather\WeatherRequestFailed;
use App\Exceptions\Weather\WeatherRequestRejected;
use App\Jobs\FetchImageTemperature;
use App\Models\Image;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Support\StubWeatherProvider;

uses(RefreshDatabase::class);

function stub_weather(float|Throwable $answer): StubWeatherProvider
{
    $stub = new StubWeatherProvider($answer);
    app()->instance(WeatherProvider::class, $stub);

    return $stub;
}

/**
 * Process one job from the real database queue, like the `queue` container does, so the
 * serialization, tries, backoff and failure handling under test are the framework's own.
 */
function work_queue_once(): void
{
    Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0]);
}

function queued_job(): stdClass
{
    return DB::table('jobs')->sole();
}

beforeEach(function (): void {
    config(['queue.default' => 'database']);
    $this->travelTo(new DateTimeImmutable('2026-09-24T10:15:42Z'));
});

it('stores the temperature and when it was fetched', function (): void {
    Log::spy();
    stub_weather(12.3);
    $image = Image::factory()->create();

    dispatch(new FetchImageTemperature($image));
    $this->travelTo(new DateTimeImmutable('2026-09-24T10:16:05Z'));
    work_queue_once();

    $image->refresh();
    expect($image->temperature_c)->toBe(12.3)
        ->and($image->temperature_fetched_at?->toIso8601String())->toBe('2026-09-24T10:16:05+00:00')
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(0);

    Log::shouldNotHaveReceived('warning');
});

it('asks for the temperature at upload time, not at execution time', function (): void {
    $weather = stub_weather(12.3);
    $image = Image::factory()->create();

    dispatch(new FetchImageTemperature($image));
    $this->travelTo(new DateTimeImmutable('2026-09-24T12:40:00Z'));
    work_queue_once();

    expect($weather->moments)->toHaveCount(1)
        ->and($weather->moments[0]->format(DATE_ATOM))->toBe('2026-09-24T10:15:42+00:00');
});

it('releases the job with backoff after a transient failure', function (): void {
    stub_weather(WeatherRequestFailed::httpStatus(503, null));
    $image = Image::factory()->create();

    dispatch(new FetchImageTemperature($image));
    work_queue_once();

    $job = queued_job();
    expect($job->attempts)->toBe(1)
        ->and($job->available_at)->toBe(now()->getTimestamp() + 10)
        ->and($image->refresh()->temperature_c)->toBeNull()
        ->and(DB::table('failed_jobs')->count())->toBe(0);
});

it('stores the temperature when a retry succeeds', function (): void {
    stub_weather(WeatherRequestFailed::httpStatus(503, null));
    $image = Image::factory()->create();

    dispatch(new FetchImageTemperature($image));
    work_queue_once();

    $weather = stub_weather(12.3);
    $this->travel(10)->seconds();
    work_queue_once();

    expect($image->refresh()->temperature_c)->toBe(12.3)
        ->and($weather->moments[0]->format(DATE_ATOM))->toBe('2026-09-24T10:15:42+00:00')
        ->and(DB::table('jobs')->count())->toBe(0);
});

it('gives up after the last try, leaves the temperature empty and logs a warning', function (): void {
    Log::spy();
    $weather = stub_weather(WeatherRequestFailed::httpStatus(503, null));
    $image = Image::factory()->create();

    dispatch(new FetchImageTemperature($image));
    work_queue_once();

    foreach ([10, 60, 300] as $backoff) {
        expect(queued_job()->available_at)->toBe(now()->getTimestamp() + $backoff);
        $this->travel($backoff)->seconds();
        work_queue_once();
    }

    expect($weather->moments)->toHaveCount(4)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(1)
        ->and($image->refresh()->temperature_c)->toBeNull()
        ->and($image->temperature_fetched_at)->toBeNull();

    Log::shouldHaveReceived('warning')->once()->withArgs(
        fn (string $message, array $context): bool => $message === 'Fetching the temperature for the uploaded image failed.'
            && $context === [
                'image_id' => $image->id,
                'uploaded_at' => '2026-09-24T10:15:42+00:00',
                'reason' => 'Weather request failed with HTTP status 503.',
            ],
    );
});

it('logs a warning and finishes without retrying when the temperature cannot be had', function (Throwable $failure): void {
    Log::spy();
    $weather = stub_weather($failure);
    $image = Image::factory()->create();

    dispatch(new FetchImageTemperature($image));
    work_queue_once();

    expect($weather->moments)->toHaveCount(1)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(0)
        ->and($image->refresh()->temperature_c)->toBeNull()
        ->and($image->temperature_fetched_at)->toBeNull();

    Log::shouldHaveReceived('warning')->once()->withArgs(
        fn (string $message, array $context): bool => $message === 'Temperature for the uploaded image is not available.'
            && $context === [
                'image_id' => $image->id,
                'uploaded_at' => '2026-09-24T10:15:42+00:00',
                'reason' => $failure->getMessage(),
            ],
    );
})->with([
    'hour no longer available' => [TemperatureNotAvailable::forHour('2026-09-24T12:00')],
    'request rejected' => [WeatherRequestRejected::httpStatus(400, 'Parameter is invalid')],
]);

it('fails at once, without retrying, when the image has no creation time', function (): void {
    $weather = stub_weather(12.3);
    $image = Image::factory()->create();
    DB::table('images')->where('id', $image->id)->update(['created_at' => null]);

    dispatch(new FetchImageTemperature($image));
    work_queue_once();

    expect($weather->moments)->toBe([])
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(1);
});

it('drops the job when the image was deleted before it ran', function (): void {
    $weather = stub_weather(12.3);
    $image = Image::factory()->create();

    dispatch(new FetchImageTemperature($image));
    $image->delete();
    work_queue_once();

    expect($weather->moments)->toBe([])
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(0);
});

it('is queued only once the surrounding transaction commits', function (): void {
    $image = Image::factory()->create();

    DB::transaction(function () use ($image): void {
        dispatch(new FetchImageTemperature($image));

        // A worker must not see the job while the image row may still be rolled back.
        expect(DB::table('jobs')->count())->toBe(0);
    });

    expect(DB::table('jobs')->count())->toBe(1);
});
