<?php

namespace Medilies\RmQ\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static int stage(string|\Stringable|iterable<mixed> $paths, ?string $disk = null)
 * @method static mixed transaction(\Closure $callback, int $attempts = 1)
 * @method static void delete()
 * @method static \Medilies\RmQ\DeletionReport deleteAll(?int $after = null, ?int $limit = null)
 * @method static array{abandoned: int, staged: int, retries: int} countDeletable(?int $after = null)
 * @method static string instanceId()
 *
 * @see \Medilies\RmQ\RmQ
 */
class RmQ extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Medilies\RmQ\RmQ::class;
    }
}
