<?php

namespace Medilies\RmQ\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Medilies\RmQ\Enums\Status;
use Medilies\RmQ\Support\Settings;

/**
 * @property int $id
 * @property string $path
 * @property ?string $disk
 * @property Status $status
 * @property string $instance
 * @property Carbon $staged_at
 * @property int $attempts
 * @property ?string $claimed_by
 * @property ?Carbon $claimed_at
 * @property ?Carbon $processed_at
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

    public static function tableName(): string
    {
        return Settings::table();
    }

    public function getTable(): string
    {
        return Settings::table();
    }

    public function getConnectionName(): ?string
    {
        return Settings::connection();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => Status::class,
            'attempts' => 'integer',
            'staged_at' => 'datetime',
            'claimed_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }
}
