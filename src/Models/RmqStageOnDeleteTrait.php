<?php

namespace Medilies\RmQ\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;
use Medilies\RmQ\RmQ;
use Medilies\RmQ\Support\Paths;

/**
 * Stages the model's files for deletion when the model is deleted, or force-deleted if it uses SoftDeletes.
 *
 * The raw column values are staged: accessors and casts are ignored. Deletions that don't fire model events are
 * not covered: deleteQuietly(), query builder deletes and database cascades.
 *
 * @mixin Model
 */
trait RmqStageOnDeleteTrait
{
    /**
     * Paths read while deleting, staged once the row is actually gone.
     *
     * @var array<string, list<string>>
     */
    private array $rmqPathsByDisk = [];

    public static function bootRmqStageOnDeleteTrait(): void
    {
        // Before the row is gone: unselected columns can still be read, and invalid paths abort the deletion.
        static::deleting(function (self $model): void {
            $model->rmqPathsByDisk = self::rmqIsSoftDeleting($model) ? [] : $model->rmqCollectPaths();
        });

        static::deleted(function (self $model): void {
            $pathsByDisk = $model->rmqPathsByDisk;
            $model->rmqPathsByDisk = [];

            if (self::rmqIsSoftDeleting($model)) {
                return;
            }

            foreach ($pathsByDisk as $disk => $paths) {
                app(RmQ::class)->stage($paths, $disk === '' ? null : $disk);
            }
        });
    }

    /**
     * The columns holding file paths: a list (`['path']`, on the rmqDisk() disk) or a map of column to disk
     * (`['avatar' => 's3', 'thumbnail']`). A null disk means absolute local paths.
     *
     * @return array<int|string, ?string>
     */
    abstract public function listFilePathColumns(): array;

    /** The disk of the columns listed without one. Null means absolute local paths. */
    public function rmqDisk(): ?string
    {
        return null;
    }

    /** A SoftDeletes model being trashed rather than force-deleted. */
    private static function rmqIsSoftDeleting(Model $model): bool
    {
        return method_exists($model, 'isForceDeleting') && ! $model->isForceDeleting();
    }

    /** @return array<string, list<string>> Paths keyed by disk ('' for local paths). */
    private function rmqCollectPaths(): array
    {
        $disks = [];

        foreach ($this->listFilePathColumns() as $key => $value) {
            if (is_string($key)) {
                $disks[$key] = $value;
            } elseif (is_string($value) && $value !== '') {
                $disks[$value] = $this->rmqDisk();
            } else {
                throw new LogicException(static::class.'::listFilePathColumns() must list column names, or map them to disks.');
            }
        }

        $values = $this->getRawOriginal();
        $missing = array_values(array_diff(array_keys($disks), array_keys($values)));

        if ($missing !== []) {
            // A partial select: read the columns before the row is deleted.
            $row = $this->newQueryWithoutScopes()->whereKey($this->getKey())->toBase()->first($missing);
            $values = [...$values, ...(array) $row];
        }

        $pathsByDisk = [];

        foreach ($disks as $column => $disk) {
            $path = $values[$column] ?? null;

            if ($path === null || $path === '') {
                continue;
            }

            foreach (Paths::normalize([$path], $disk) as $normalized) {
                $pathsByDisk[$disk ?? ''][] = $normalized;
            }
        }

        return $pathsByDisk;
    }
}
