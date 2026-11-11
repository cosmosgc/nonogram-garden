<?php

use App\Http\Middleware\RecordRequestContext;
use App\Support\Logging\StructuredErrorReporter;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            RecordRequestContext::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Replace Laravel's default exception log with a structured, redacted
        // record. Returning false stops the default logger, whose message and
        // trace can include query bindings such as puzzle solutions. If the
        // structured channel cannot be written, fall back to the framework
        // logger so a failure is never silent.
        $exceptions->report(function (Throwable $exception): bool {
            $reporter = app(StructuredErrorReporter::class);

            if (! $reporter->shouldReport($exception)) {
                return false;
            }

            return $reporter->report($exception) === null;
        });

        $exceptions->respond(function (Response $response): Response {
            $requestId = Context::get('request_id');

            if (is_string($requestId) && $requestId !== '') {
                $response->headers->set(RecordRequestContext::HEADER, $requestId);
            }

            return $response;
        });
    })->create();
