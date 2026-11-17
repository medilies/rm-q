<?php

namespace Medilies\RmQ\Casts;

use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;

/**
 * A datetime stored as UTC wall-clock time, returned in the app timezone.
 *
 * @internal
 *
 * @implements CastsAttributes<CarbonInterface, DateTimeInterface|string>
 */
final class UtcDateTime implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?CarbonInterface
    {
        $date = match (true) {
            $value instanceof DateTimeInterface => Date::instance($value),
            is_string($value) => Date::parse($value, 'UTC'),
            default => null,
        };

        return $date?->setTimezone(date_default_timezone_get());
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        $date = match (true) {
            $value instanceof DateTimeInterface => Date::instance($value),
            is_string($value) => Date::parse($value), // In the app timezone, like Eloquent's datetime cast.
            default => null,
        };

        return $date?->utc()->format($model->getDateFormat());
    }
}
