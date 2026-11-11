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
        // record. The reporter tries the default channel with that same safe
        // record if its dedicated channel fails. Returning false prevents
        // Laravel from writing an unredacted exception message and trace.
        $exceptions->report(function (Throwable $exception): bool {
            $reporter = app(StructuredErrorReporter::class);

            if (! $reporter->shouldReport($exception)) {
                return false;
            }

            $reporter->report($exception);

            return false;
        });

        $exceptions->respond(function (Response $response): Response {
            $requestId = Context::get('request_id');

            if (is_string($requestId) && $requestId !== '') {
                $response->headers->set(RecordRequestContext::HEADER, $requestId);
            }

            return $response;
        });
    })->create();
