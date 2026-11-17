<?php

namespace Medilies\RmQ\Support;

use Illuminate\Support\Facades\Config;
use Medilies\RmQ\Exceptions\RmqException;
use Stringable;

/** @internal */
final class Paths
{
    /** Scoped disks over scoped disks: deeper chains are not treated as local. */
    private const int MAX_SCOPE_DEPTH = 10;

    /**
     * The configured disk files are staged on: the given one, or `filesystems.default`.
     *
     * @throws RmqException
     */
    public static function disk(?string $disk): string
    {
        $disk ??= Config::get('filesystems.default');

        if (! is_string($disk) || $disk === '') {
            throw RmqException::invalidConfig('filesystems.default', 'a disk name');
        }

        if (! is_array(self::diskConfig($disk))) {
            throw RmqException::unknownDisk($disk);
        }

        return $disk;
    }

    /**
     * Validate and de-duplicate paths before staging them.
     *
     * @param  string|Stringable|iterable<mixed>  $paths
     * @return list<string>
     *
     * @throws RmqException
     */
    public static function normalize(string|Stringable|iterable $paths): array
    {
        $normalized = [];

        foreach (is_iterable($paths) ? $paths : [$paths] as $path) {
            if ($path instanceof Stringable) {
                $path = (string) $path;
            }

            if (! is_string($path)) {
                throw RmqException::invalidPath(get_debug_type($path), 'must be a string');
            }

            $violation = self::violation($path);

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
    public static function violation(string $path): ?string
    {
        return match (true) {
            $path === '' => 'must not be empty',
            str_contains($path, "\0") => 'must not contain NUL bytes',
            preg_match('~^[a-z][a-z0-9+.\-]*://~i', $path) === 1 => 'must not use a stream wrapper',
            preg_match('~(^|[/\\\\])\.\.([/\\\\]|$)~', $path) === 1 => 'must not contain ".." segments',
            default => null,
        };
    }

    /**
     * Whether the disk is configured with the `local` driver, or `scoped` over such a disk. The configured driver,
     * not the resolved one: a disk faked with Storage::fake() keeps the semantics of the driver it stands in for.
     */
    public static function isLocalDisk(string $disk): bool
    {
        $config = self::diskConfig($disk);

        for ($depth = 0; is_array($config) && $depth < self::MAX_SCOPE_DEPTH; $depth++) {
            if (($config['driver'] ?? null) !== 'scoped') {
                return ($config['driver'] ?? null) === 'local';
            }

            $parent = $config['disk'] ?? null;
            $config = is_string($parent) ? self::diskConfig($parent) : $parent;
        }

        return false;
    }

    private static function diskConfig(string $disk): mixed
    {
        $disks = Config::get('filesystems.disks');

        return is_array($disks) ? $disks[$disk] ?? null : null;
    }
}
