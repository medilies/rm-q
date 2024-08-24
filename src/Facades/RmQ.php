<?php

namespace Medilies\RmQ\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static void stage(array|string $paths)
 * @method static void delete()
 * @method static void deleteAll()
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
