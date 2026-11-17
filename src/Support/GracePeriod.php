<?php

namespace Medilies\RmQ\Support;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

/**
 * The `rm-q.after` grace period. It comes from `.env`, so an invalid value falls back to the default with a warning
 * instead of turning into 0 and deleting files at the next run.
 *
 * @internal
 */
final class GracePeriod
{
    private const int DEFAULT = 60 * 60 * 24;

    private function __construct(
        public readonly int $seconds,
        public readonly ?string $warning,
    ) {}

    /** Reads `rm-q.after`; null or missing means the default, and an invalid value is logged and replaced by it. */
    public static function fromConfig(): self
    {
        $value = Config::get('rm-q.after');

        if ($value === null) {
            return new self(self::DEFAULT, null);
        }

        if ((is_int($value) && $value >= 0) || (is_string($value) && ctype_digit($value))) {
            return new self((int) $value, null);
        }

        $warning = sprintf(
            'rm-q.after must be a non-negative integer of seconds, got %s; using the default of %d (1 day).',
            self::describe($value),
            self::DEFAULT,
        );

        Log::warning($warning);

        return new self(self::DEFAULT, $warning);
    }

    private static function describe(mixed $value): string
    {
        if (! is_scalar($value) && ! is_array($value)) {
            return get_debug_type($value);
        }

        $flags = JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
            | JSON_INVALID_UTF8_SUBSTITUTE
            | JSON_PRESERVE_ZERO_FRACTION;

        // json_encode() fails on INF and NAN.
        return json_encode($value, $flags) ?: get_debug_type($value);
    }
}
