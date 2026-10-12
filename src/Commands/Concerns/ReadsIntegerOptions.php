<?php

namespace Medilies\RmQ\Commands\Concerns;

use InvalidArgumentException;

/** @internal */
trait ReadsIntegerOptions
{
    /** @throws InvalidArgumentException */
    protected function integerOption(string $name, int $min): ?int
    {
        $value = $this->option($name);

        if ($value === null) {
            return null;
        }

        if (! is_string($value) || ! ctype_digit($value) || (int) $value < $min) {
            throw new InvalidArgumentException("The --{$name} option must be an integer greater than or equal to {$min}.");
        }

        return (int) $value;
    }
}
