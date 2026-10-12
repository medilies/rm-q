<?php

namespace Medilies\RmQ\Support;

use Illuminate\Support\Facades\Config;
use Medilies\RmQ\Exceptions\RmqException;
use Stringable;

/** @internal */
final class Paths
{
    /**
     * Validate and de-duplicate paths before staging them.
     *
     * @param  string|Stringable|iterable<mixed>  $paths
     * @return list<string>
     *
     * @throws RmqException
     */
    public static function normalize(string|Stringable|iterable $paths, ?string $disk): array
    {
        if ($disk !== null && ! self::diskExists($disk)) {
            throw RmqException::unknownDisk($disk);
        }

        $normalized = [];

        foreach (is_iterable($paths) ? $paths : [$paths] as $path) {
            if ($path instanceof Stringable) {
                $path = (string) $path;
            }

            if (! is_string($path)) {
                throw RmqException::invalidPath(get_debug_type($path), 'must be a string');
            }

            $violation = self::violation($path, $disk);

            if ($violation !== null) {
                throw RmqException::invalidPath($path, $violation);
            }

            $normalized[] = $path;
        }

        // Not array keys: numeric-string paths would turn into integers.
        return array_values(array_unique($normalized));
    }

    /**
     * Why a path must not be deleted, or null when it may be. Also checked again right before deleting,
     * since rows can be inserted by other means than RmQ::stage().
     */
    public static function violation(string $path, ?string $disk): ?string
    {
        return match (true) {
            $path === '' => 'must not be empty',
            str_contains($path, "\0") => 'must not contain NUL bytes',
            preg_match('~^[a-z][a-z0-9+.\-]*://~i', $path) === 1 => 'must not use a stream wrapper',
            preg_match('~(^|[/\\\\])\.\.([/\\\\]|$)~', $path) === 1 => 'must not contain ".." segments',
            $disk !== null => null,
            ! self::isAbsolute($path) => 'must be absolute when no disk is given',
            ! self::isAllowed($path) => 'must be inside one of the rm-q.allowed_directories',
            default => null,
        };
    }

    public static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\\\') // UNC: \\host\share
            || preg_match('~^[A-Za-z]:[/\\\\]~', $path) === 1;
    }

    private static function isAllowed(string $path): bool
    {
        $directories = Settings::allowedDirectories();

        if ($directories === null) {
            return true;
        }

        $path = self::comparable($path);

        foreach ($directories as $directory) {
            // Sound because ".." segments are rejected before this check.
            if (str_starts_with($path, rtrim(self::comparable($directory), '/').'/')) {
                return true;
            }
        }

        return false;
    }

    private static function comparable(string $path): string
    {
        $path = str_replace('\\', '/', $path);

        return PHP_OS_FAMILY === 'Windows' ? strtolower($path) : $path;
    }

    private static function diskExists(string $disk): bool
    {
        $disks = Config::get('filesystems.disks');

        return is_array($disks) && is_array($disks[$disk] ?? null);
    }
}
