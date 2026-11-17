<?php

namespace Medilies\RmQ\Exceptions;

use Exception;

class RmqException extends Exception
{
    public static function invalidPath(string $path, string $reason): self
    {
        return new self("Cannot stage [{$path}] for deletion: the path {$reason}.");
    }

    public static function unknownDisk(string $disk): self
    {
        return new self("Cannot stage files on disk [{$disk}]: the disk is not configured.");
    }

    public static function invalidConfig(string $key, string $expected): self
    {
        return new self("The [{$key}] config must be {$expected}.");
    }

    public static function invalidArgument(string $name, string $expected): self
    {
        return new self("The [{$name}] argument must be {$expected}.");
    }

    public static function insideTransaction(): self
    {
        return new self('RmQ::deleteAll() cannot run inside a database transaction: it could delete files whose staging may still roll back.');
    }
}
