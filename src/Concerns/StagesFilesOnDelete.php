<?php

namespace Medilies\RmQ\Concerns;

use Illuminate\Database\Eloquent\Model;
use Medilies\RmQ\Exceptions\RmqException;
use Medilies\RmQ\Models\RmqFile;
use Medilies\RmQ\RmQ;
use Medilies\RmQ\Support\Paths;

/**
 * Stages the model's files for deletion when the model is deleted, or force-deleted if it uses SoftDeletes.
 *
 * The raw column values are staged: accessors and casts are ignored. Deletions that don't fire model events are
 * not covered: deleteQuietly(), forceDeleteQuietly(), query builder deletes and database cascades.
 *
 * @mixin Model
 */
trait StagesFilesOnDelete
{
    /**
     * Paths read while deleting, staged once the row is actually gone.
     *
     * @var array<string, list<string>>
     */
    private array $rmqPathsByDisk = [];

    public static function bootStagesFilesOnDelete(): void
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
                app(RmQ::class)->stage($paths, $disk);
            }
        });
    }

    /**
     * The columns holding file paths, mapped to their disk (`['avatar' => 's3']`). A null disk means
     * `filesystems.default`.
     *
     * @return array<string, ?string>
     */
    abstract public function rmqFileColumns(): array;

    /**
     * Cancel the deletion of the model's files, e.g. after restoring it from a backup.
     *
     * @return int The number of rows removed.
     *
     * @throws RmqException When a path or a disk is invalid, or the model is on another connection than rm-q.
     */
    public function rmqUnstageFiles(): int
    {
        $removed = 0;

        foreach ($this->rmqCollectPaths() as $disk => $paths) {
            $removed += app(RmQ::class)->unstage($paths, $disk);
        }

        return $removed;
    }

    /** A SoftDeletes model being trashed rather than force-deleted. */
    private static function rmqIsSoftDeleting(Model $model): bool
    {
        return method_exists($model, 'isForceDeleting') && ! $model->isForceDeleting();
    }

    /** @return array<string, list<string>> Paths keyed by disk. */
    private function rmqCollectPaths(): array
    {
        // On another connection, the staging would commit even if the deletion rolls back.
        $connection = $this->getConnection()->getName();
        $rmqConnection = (new RmqFile)->getConnection()->getName();

        if ($connection !== $rmqConnection) {
            throw RmqException::connectionMismatch(static::class, $connection, $rmqConnection);
        }

        $disks = $this->rmqFileColumns();
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

            foreach (Paths::normalize([$path]) as $normalized) {
                $pathsByDisk[Paths::disk($disk)][] = $normalized;
            }
        }

        return $pathsByDisk;
    }
}
