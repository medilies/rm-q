<?php

namespace Medilies\RmQ\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Medilies\RmQ\Exceptions\RmqException;

/**
 * A staged file: removed once the file is deleted.
 *
 * @property int $id
 * @property string $path
 * @property string $disk
 * @property Carbon $staged_at
 * @property int $attempts
 * @property ?string $error
 */
final class RmqFile extends Model
{
    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * The attributes that aren't mass assignable.
     *
     * @var array<string>
     */
    protected $guarded = ['id'];

    public function getTable(): string
    {
        // The default covers apps booted without the package config, e.g. by Larastan.
        return Config::string('rm-q.table', 'rm_q_files');
    }

    /** Must be the connection your business transactions run on. Null is the default connection. */
    public function getConnectionName(): ?string
    {
        $connection = Config::get('rm-q.connection');

        if ($connection !== null && ! is_string($connection)) {
            throw RmqException::invalidConfig('rm-q.connection', 'a connection name or null');
        }

        return $connection;
    }

    /**
     * Files that used up their attempts: only `rm-q:retry` brings them back.
     *
     * @param  Builder<self>  $query
     */
    public function scopeGaveUp(Builder $query): void
    {
        $query->where('attempts', '>=', Config::integer('rm-q.max_attempts'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'staged_at' => 'datetime',
        ];
    }
}
