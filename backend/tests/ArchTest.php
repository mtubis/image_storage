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

arch('services do not depend on the HTTP layer')
    ->expect('App\Services')
    ->not->toUse(['Illuminate\Http', 'App\Http']);
