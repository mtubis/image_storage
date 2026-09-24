<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\WeatherProvider;
use App\Exceptions\Weather\TemperatureNotAvailable;
use App\Exceptions\Weather\WeatherRequestFailed;
use App\Exceptions\Weather\WeatherRequestRejected;
use App\Models\Image;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Facades\Log;
use LogicException;
use Throwable;

/**
 * Records the air temperature at the image's upload time.
 *
 * Queued after commit: the image is created inside a transaction, and a worker picking the job
 * up earlier would not find the row (and, with DeleteWhenMissingModels, drop it silently).
 * A deleted image needs no temperature, so a job for it is discarded instead of failing.
 */
#[Tries(4)]
#[Backoff(10, 60, 300)]
// Covers the provider's own HTTP attempts and timeouts (config/services.php) with a margin.
// Enforced by the worker through ext-pcntl.
#[Timeout(30)]
#[DeleteWhenMissingModels]
final class FetchImageTemperature implements ShouldQueueAfterCommit
{
    use Queueable;

    public function __construct(
        public readonly Image $image,
    ) {}

    /**
     * @throws WeatherRequestFailed when the provider is temporarily unavailable; the queue
     *                              retries the job with backoff
     */
    public function handle(WeatherProvider $weather): void
    {
        if ($this->image->created_at === null) {
            // Without the upload time there is no hour to ask for; retrying cannot change that.
            $this->fail(new LogicException("Image [{$this->image->id}] has no creation time."));

            return;
        }

        try {
            // The upload time, not "now": a retry must still record the temperature of that hour.
            $reading = $weather->temperatureAt($this->image->created_at);
        } catch (TemperatureNotAvailable|WeatherRequestRejected $exception) {
            // Retrying cannot help, and the temperature is optional: keep it empty, don't fail.
            Log::warning('Temperature for the uploaded image is not available.', $this->logContext($exception));

            return;
        }

        $this->image->update([
            'temperature_c' => $reading->celsius,
            'temperature_fetched_at' => now(),
        ]);
    }

    /**
     * Called once the queue gives up (tries exhausted or fail()). The framework reports the
     * exception itself; this adds which image is left without a temperature.
     */
    public function failed(?Throwable $exception): void
    {
        Log::warning('Fetching the temperature for the uploaded image failed.', $this->logContext($exception));
    }

    /**
     * @return array{image_id: string, uploaded_at: string|null, reason: string|null}
     */
    private function logContext(?Throwable $exception): array
    {
        return [
            'image_id' => $this->image->id,
            'uploaded_at' => $this->image->created_at?->format(DateTimeInterface::ATOM),
            'reason' => $exception?->getMessage(),
        ];
    }
}
