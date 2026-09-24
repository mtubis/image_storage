<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use RectorLaravel\Rector\ArrayDimFetch\ServerVariableToRequestFacadeRector;
use RectorLaravel\Set\LaravelSetList;

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/app',
        __DIR__.'/bootstrap/app.php',
        __DIR__.'/config',
        __DIR__.'/database',
        __DIR__.'/routes',
        __DIR__.'/tests',
    ])
    ->withPhpSets()
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
        earlyReturn: true,
    )
    ->withComposerBased(laravel: true)
    ->withSets([
        LaravelSetList::LARAVEL_CODE_QUALITY,
        LaravelSetList::LARAVEL_COLLECTION,
        LaravelSetList::LARAVEL_TYPE_DECLARATIONS,
    ])
    ->withSkip([
        // Sets the process-level $_SERVER that env() reads, not the current request's server bag.
        ServerVariableToRequestFacadeRector::class => [__DIR__.'/tests/Unit/Config/CorsConfigTest.php'],
    ]);
