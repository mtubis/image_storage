<?php

declare(strict_types=1);

arch()->preset()->php();

// Besides naming rules, forbids dd/ddd/exit and env() outside config files.
arch()->preset()->laravel();

arch()->preset()->security();

arch('application code uses strict types')
    ->expect(['App', 'Database', 'Tests'])
    ->toUseStrictTypes();

arch('contracts are interfaces')
    ->expect('App\Contracts')
    ->toBeInterfaces();

// The HTTP *server* layer (requests, responses, uploads); adapters may use the HTTP client.
arch('actions, services and jobs do not depend on the HTTP layer')
    ->expect(['App\Actions', 'App\Services', 'App\Jobs'])
    ->not->toUse(['Illuminate\Http', 'App\Http'])
    ->ignoring('Illuminate\Http\Client');

arch('DTOs are immutable')
    ->expect('App\Data')
    ->toBeFinal()
    ->toBeReadonly();

// Consumers depend on contracts (bound in AppServiceProvider), never on a concrete adapter.
arch('actions and jobs depend on contracts, not adapters')
    ->expect(['App\Actions', 'App\Jobs'])
    ->not->toUse('App\Services');
