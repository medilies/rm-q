<?php

namespace Medilies\RmQ\Support;

/** @internal */
final class Outcome
{
    private function __construct(
        public readonly bool $deleted,
        public readonly string $error = '',
    ) {}

    public static function deleted(): self
    {
        return new self(true);
    }

    public static function failed(string $error): self
    {
        return new self(false, $error);
    }
}
