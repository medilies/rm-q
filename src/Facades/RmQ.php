<?php

namespace Medilies\RmQ\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Medilies\RmQ\RmQ
 */
class RmQ extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Medilies\RmQ\RmQ::class;
    }
}
