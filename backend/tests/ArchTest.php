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
arch('services do not depend on the HTTP layer')
    ->expect('App\Services')
    ->not->toUse(['Illuminate\Http', 'App\Http'])
    ->ignoring('Illuminate\Http\Client');

arch('DTOs are immutable')
    ->expect('App\Data')
    ->toBeFinal()
    ->toBeReadonly();
