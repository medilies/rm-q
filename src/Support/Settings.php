<?php

namespace Medilies\RmQ\Support;

use Illuminate\Support\Facades\Config;
use Medilies\RmQ\Exceptions\RmqException;

/**
 * Typed access to the `rm-q` config, read at call time so per-request config (Octane) is respected.
 *
 * @internal
 */
final class Settings
{
    /** A non-negative integer setting; numeric strings (from `env()`) are accepted. */
    public static function int(string $key, int $min = 0): int
    {
        $value = Config::get("rm-q.{$key}");

        if (is_string($value) && ctype_digit($value)) {
            $value = (int) $value;
        }

        if (! is_int($value) || $value < $min) {
            throw RmqException::invalidConfig("rm-q.{$key}", "an integer greater than or equal to {$min}");
        }

        return $value;
    }

    public static function connection(): ?string
    {
        $value = Config::get('rm-q.connection');

        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value)) {
            throw RmqException::invalidConfig('rm-q.connection', 'a connection name or null');
        }

        return $value;
    }

    public static function table(): string
    {
        $value = Config::get('rm-q.table', 'rm_q_files');

        if (! is_string($value) || $value === '') {
            throw RmqException::invalidConfig('rm-q.table', 'a non-empty string');
        }

        return $value;
    }

    /** @return list<string>|null */
    public static function allowedDirectories(): ?array
    {
        $value = Config::get('rm-q.allowed_directories');

        if ($value === null) {
            return null;
        }

        if (! is_array($value)) {
            throw RmqException::invalidConfig('rm-q.allowed_directories', 'null or a list of absolute directories');
        }

        $directories = [];

        foreach ($value as $directory) {
            if (! is_string($directory) || ! Paths::isAbsolute($directory)) {
                throw RmqException::invalidConfig('rm-q.allowed_directories', 'null or a list of absolute directories');
            }

            $directories[] = $directory;
        }

        return $directories;
    }
}
