<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request): bool => $request->is('api', 'api/*') || $request->expectsJson(),
        );

        // Laravel's message names the model class and echoes the id (even without debug),
        // and differs between an unknown route and an unknown image; clients need neither.
        $exceptions->render(
            fn (NotFoundHttpException $e, Request $request): ?JsonResponse => $request->is('api', 'api/*')
                ? new JsonResponse(['message' => 'Not found.'], JsonResponse::HTTP_NOT_FOUND)
                : null,
        );
    })->create();
