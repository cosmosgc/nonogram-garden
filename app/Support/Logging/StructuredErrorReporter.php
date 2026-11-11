<?php

namespace App\Support\Logging;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use PDOException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Writes one structured JSON record per unhandled exception.
 *
 * Only request metadata is recorded — never bodies, query values, session data,
 * puzzle solutions, clues, markers, or image contents. The record is written to
 * a dedicated channel so operational log shipping can collect it without also
 * collecting unrelated framework chatter.
 */
final class StructuredErrorReporter
{
    private const MAX_PREVIOUS = 3;

    private const MAX_TRACE_FRAMES = 15;

    public function __construct(private readonly SensitiveDataRedactor $redactor) {}

    /**
     * Reports an exception and returns the redacted record that was logged, or
     * null when neither the dedicated channel nor the safe fallback could be written.
     */
    public function report(Throwable $exception): ?array
    {
        $record = $this->record($exception);

        try {
            Log::channel((string) config('logging.error_report.channel', 'errors'))
                ->error('unhandled_exception', $record);
        } catch (Throwable) {
            try {
                Log::channel((string) config('logging.default', 'stack'))
                    ->error('unhandled_exception', $record + ['reporter_fallback' => true]);
            } catch (Throwable) {
                // Reporting must never replace or mask the original failure.
            }

            return null;
        }

        return $record;
    }

    /** Server failures are reported; expected client errors stay out of the error log. */
    public function shouldReport(Throwable $exception): bool
    {
        return $this->statusOf($exception) >= 500;
    }

    /** @return array<string, mixed> */
    public function record(Throwable $exception): array
    {
        $http = Context::get('http');
        $http = is_array($http) ? $http : [];
        $status = $this->statusOf($exception);

        $record = [
            'event' => 'unhandled_exception',
            'severity' => $status >= 500 ? 'error' : 'warning',
            'exception' => $exception::class,
            'message' => $this->messageFor($exception),
            'code' => is_scalar($exception->getCode()) ? $exception->getCode() : null,
            'status' => $status,
            'file' => $this->relativePath($exception->getFile()),
            'line' => $exception->getLine(),
            'request_id' => $this->stringOrNull(Context::get('request_id')),
            'method' => $this->stringOrNull($http['method'] ?? null),
            'route' => $this->stringOrNull($http['route'] ?? null),
            'path' => $this->stringOrNull($http['path'] ?? null),
            'user_id' => $this->userId(),
            'environment' => app()->environment(),
        ];

        if ($exception instanceof QueryException) {
            $record['database'] = [
                'connection' => $this->redactor->scrubString((string) $exception->getConnectionName()),
                // Unprepared SQL keeps `?` placeholders, so bound values (including
                // solutions or credentials) never reach the log.
                'sql' => $this->redactor->scrubString($exception->getSql()),
            ];
        }

        $record['previous'] = $this->previousChain($exception);
        $record['trace'] = $this->trace($exception);

        return $record;
    }

    public function statusOf(Throwable $exception): int
    {
        return $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 500;
    }

    private function messageFor(Throwable $exception): string
    {
        if ($exception instanceof QueryException) {
            return 'Database query failed.';
        }

        // Driver errors can embed row values (for example a duplicate e-mail
        // address), so keep only the SQLSTATE heading and drop the payload tail.
        if ($exception instanceof PDOException) {
            $message = $exception->getMessage();

            if (preg_match('/^(SQLSTATE\[[A-Z0-9]+\][^:]*)/', $message, $matches) === 1) {
                return $this->redactor->scrubString($matches[1]);
            }

            return 'Database driver error.';
        }

        return $this->redactor->scrubString($exception->getMessage());
    }

    /** @return list<array{exception: class-string<Throwable>, message: string}> */
    private function previousChain(Throwable $exception): array
    {
        $chain = [];
        $previous = $exception->getPrevious();

        while ($previous !== null && count($chain) < self::MAX_PREVIOUS) {
            $chain[] = [
                'exception' => $previous::class,
                'message' => $this->messageFor($previous),
            ];
            $previous = $previous->getPrevious();
        }

        return $chain;
    }

    /**
     * Frames carry function names and locations only. Argument values are
     * deliberately omitted: `getTraceAsString()` includes scalar arguments,
     * which can be request bodies, solutions, or credentials.
     *
     * @return list<array{function: string, file: string|null, line: int|null}>
     */
    private function trace(Throwable $exception): array
    {
        $frames = [];

        foreach (array_slice($exception->getTrace(), 0, self::MAX_TRACE_FRAMES) as $frame) {
            $function = ($frame['class'] ?? '').($frame['type'] ?? '').($frame['function'] ?? '{closure}');
            $file = $frame['file'] ?? null;

            $frames[] = [
                'function' => $this->redactor->scrubString($function),
                'file' => is_string($file) ? $this->redactor->scrubString($this->relativePath($file)) : null,
                'line' => is_int($frame['line'] ?? null) ? $frame['line'] : null,
            ];
        }

        return $frames;
    }

    private function relativePath(string $value): string
    {
        $base = base_path();

        return str_replace([$base.DIRECTORY_SEPARATOR, $base.'/'], '', $value);
    }

    private function userId(): int|string|null
    {
        try {
            $id = Auth::id();
        } catch (Throwable) {
            return null;
        }

        return is_int($id) || is_string($id) ? $id : null;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
