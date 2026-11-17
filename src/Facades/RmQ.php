<?php

namespace Medilies\RmQ\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static int stage(string|\Stringable|iterable<mixed> $paths, ?string $disk = null)
 * @method static \Medilies\RmQ\DeletionReport deleteAll(?int $after = null, ?int $limit = null)
 * @method static int countDeletable(?int $after = null)
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
