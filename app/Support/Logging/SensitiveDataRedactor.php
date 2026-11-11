<?php

namespace App\Support\Logging;

use DateTimeInterface;
use Throwable;

/**
 * Scrubs credentials, personal data, and canonical puzzle answers from log data.
 *
 * Error reports must never contain request bodies, solution cells, clues,
 * player marks, image data, passwords, or e-mail addresses. Reports copy safe,
 * explicit fields; this redactor is the fallback for arrays and free-form text
 * such as exception messages and query errors.
 */
final class SensitiveDataRedactor
{
    public const PLACEHOLDER = '[redacted]';

    public const MAX_STRING_LENGTH = 512;

    public const MAX_DEPTH = 6;

    public const MAX_ITEMS = 50;

    /**
     * Keys are compared after lowercasing and stripping non-alphanumeric characters,
     * so `password`, `Password_Confirmation`, and `passwordConfirmation` all match.
     *
     * @var list<string>
     */
    private const SENSITIVE_KEYS = [
        'answer',
        'apitoken',
        'artwork',
        'artworkdata',
        'authorization',
        'base64',
        'blob',
        'cells',
        'clue',
        'clues',
        'clientsecret',
        'columnclues',
        'cookie',
        'cookies',
        'csrf',
        'csrftoken',
        'currentpassword',
        'dataurl',
        'draftcells',
        'email',
        'emailaddress',
        'encodedsolution',
        'filecontents',
        'grid',
        'image',
        'imagedata',
        'marks',
        'newpassword',
        'password',
        'passwordconfirmation',
        'privatekey',
        'progressmarks',
        'refreshtoken',
        'remember',
        'remembertoken',
        'rowclues',
        'secret',
        'session',
        'sessionid',
        'signature',
        'solution',
        'solutioncells',
        'solutionhash',
        'solutions',
        'token',
    ];

    /**
     * Free-text patterns for secrets that slip into exception messages, query
     * errors, or third-party error strings. Order matters: data URLs before
     * binary cell runs.
     *
     * @var array<string, string>
     */
    private const SCRUB_PATTERNS = [
        '/data:[a-z0-9.+-]+\/[a-z0-9.+-]+;base64,[A-Za-z0-9+\/=]{16,}/i' => '[redacted:data-url]',
        '/Bearer\s+[A-Za-z0-9._~+\/=-]{8,}/i' => 'Bearer [redacted]',
        '/\beyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{4,}\.[A-Za-z0-9_-]{4,}\b/' => '[redacted:token]',
        '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/' => '[redacted:email]',
        '/(?<![01])[01]{16,}(?![01])/' => '[redacted:cells]',
    ];

    /**
     * Recursively removes sensitive values from a context array.
     *
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public function scrubArray(array $data, int $depth = 0): array
    {
        if ($depth >= self::MAX_DEPTH) {
            return ['[truncated:max-depth]'];
        }

        $clean = [];
        $count = 0;

        foreach ($data as $key => $value) {
            if ($count++ >= self::MAX_ITEMS) {
                $clean[] = '[truncated:max-items]';

                break;
            }

            if (is_string($key) && $this->isSensitiveKey($key)) {
                $clean[$key] = self::PLACEHOLDER;

                continue;
            }

            $clean[$key] = $this->scrub($value, $depth + 1);
        }

        return $clean;
    }

    /** Scrubs a single value of an unknown type. */
    public function scrub(mixed $value, int $depth = 0): mixed
    {
        if ($depth > self::MAX_DEPTH) {
            return '[truncated:max-depth]';
        }

        if (is_string($value)) {
            return $this->scrubString($value);
        }

        if (is_array($value)) {
            return $this->scrubArray($value, $depth);
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        if ($value instanceof Throwable) {
            return $value::class;
        }

        if (is_object($value)) {
            return $value::class;
        }

        if (is_scalar($value) || $value === null) {
            return $value;
        }

        return get_debug_type($value);
    }

    /** Removes secrets, e-mail addresses, and encoded answers from free text. */
    public function scrubString(string $value): string
    {
        foreach (self::SCRUB_PATTERNS as $pattern => $replacement) {
            $scrubbed = preg_replace($pattern, $replacement, $value);

            if (is_string($scrubbed)) {
                $value = $scrubbed;
            }
        }

        $value = $this->normalizeEncoding($value);

        if (strlen($value) > self::MAX_STRING_LENGTH) {
            $dropped = strlen($value) - self::MAX_STRING_LENGTH;
            $value = substr($value, 0, self::MAX_STRING_LENGTH).'…[truncated '.$dropped.' bytes]';
        }

        return $value;
    }

    public function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower((string) preg_replace('/[^a-z0-9]/i', '', $key));

        return in_array($normalized, self::SENSITIVE_KEYS, true);
    }

    private function normalizeEncoding(string $value): string
    {
        if (! mb_check_encoding($value, 'UTF-8')) {
            $value = (string) mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        }

        $withoutControlCharacters = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '?', $value);

        return is_string($withoutControlCharacters) ? $withoutControlCharacters : $value;
    }
}
