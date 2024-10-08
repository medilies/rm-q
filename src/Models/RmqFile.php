<?php

namespace Medilies\RmQ\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $path
 * @property int $status
 * @property ?string $instance
 * @property ?Carbon $staged_at
 * @property ?Carbon $processed_at
 * @property ?Carbon $deleted_at
 *
 * @method static Builder|static whereStaged()
 * @method static Builder|static whereDeleted()
 * @method static Builder|static whereFailed()
 * @method static Builder|static whereInstance(?string $instance)
 * @method static Builder|static whereBeforeSeconds(int $beforeSeconds)
 */
final class RmqFile extends Model
{
    public const STAGED = 0;

    public const DELETED = 1;

    public const FAILED = 2;

    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * The attributes that aren't mass assignable.
     *
     * @var array<string>|bool
     */
    protected $guarded = ['id'];

    public static function tableName(): string
    {
        return (new self)->getTable();
    }

    /** @param Builder<static> $query */
    public function scopeWhereStaged(Builder $query): void
    {
        $query->where('status', self::STAGED);
    }

    /** @param Builder<static> $query */
    public function scopeWhereDeleted(Builder $query): void
    {
        $query->where('status', self::DELETED);
    }

    /** @param Builder<static> $query */
    public function scopeWhereFailed(Builder $query): void
    {
        $query->where('status', self::FAILED);
    }

    /** @param Builder<static> $query */
    public function scopeWhereInstance(Builder $query, ?string $instance): void
    {
        if (is_null($instance)) {
            return;
        }

        $query->where('instance', $instance);
    }

    /** @param Builder<static> $query */
    public function scopeWhereBeforeSeconds(Builder $query, int $beforeSeconds): void
    {
        if ($beforeSeconds <= 0) {
            return;
        }

        $query->where('staged_at', '<=', Carbon::now()->subSeconds($beforeSeconds));
    }
}
