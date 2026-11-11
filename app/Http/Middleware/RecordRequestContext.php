<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Records non-payload request metadata for error reports and echoes a
 * correlation id so a user-visible failure can be matched to a log record.
 *
 * Incoming ids are only accepted when they look like an opaque token, so a
 * crafted header cannot inject control characters into logs or responses.
 */
final class RecordRequestContext
{
    public const HEADER = 'X-Request-Id';

    private const VALID_REQUEST_ID = '/^[A-Za-z0-9._:-]{8,64}$/';

    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $this->requestId($request);

        Context::add([
            'request_id' => $requestId,
            'http' => [
                'method' => $request->getMethod(),
                'path' => '/'.ltrim($request->path(), '/'),
                'route' => $request->route()?->getName(),
            ],
        ]);

        $response = $next($request);
        $response->headers->set(self::HEADER, $requestId);

        return $response;
    }

    private function requestId(Request $request): string
    {
        $incoming = trim((string) $request->headers->get(self::HEADER, ''));

        return preg_match(self::VALID_REQUEST_ID, $incoming) === 1 ? $incoming : (string) Str::uuid();
    }
}
