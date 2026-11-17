<?php

namespace Medilies\RmQ;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\DatabaseTransactionRecord;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Medilies\RmQ\Events\FileDeletionFailed;
use Medilies\RmQ\Events\StagedFilesProcessed;
use Medilies\RmQ\Exceptions\RmqException;
use Medilies\RmQ\Models\RmqFile;
use Medilies\RmQ\Support\GracePeriod;
use Medilies\RmQ\Support\Paths;
use Stringable;
use Throwable;

/**
 * Staging is a plain INSERT that joins whatever transaction is open on the rm-q connection, so only committed rows
 * are ever deleted. Runs must not overlap: schedule the command withoutOverlapping() and onOneServer().
 */
class RmQ
{
    /** 3 columns per row: stays well below SQL Server's 2100 bindings limit. */
    private const int INSERT_CHUNK = 150;

    /**
     * Stage files for deletion. The rows join (and roll back with) any transaction open on the rm-q connection.
     *
     * @param  string|Stringable|iterable<mixed>  $paths  Paths on $disk.
     * @param  string|null  $disk  A configured disk; defaults to `filesystems.default`.
     * @return int The number of staged paths (duplicates are staged once).
     *
     * @throws RmqException When a path or the disk is invalid.
     */
    public function stage(string|Stringable|iterable $paths, ?string $disk = null): int
    {
        $disk = Paths::disk($disk);
        $paths = Paths::normalize($paths);
        // UTC: the grace period is elapsed time, whatever app.timezone is or was.
        $now = Date::now('UTC');

        foreach (array_chunk($paths, self::INSERT_CHUNK) as $chunk) {
            RmqFile::query()->insert(array_map(fn (string $path): array => [
                'path' => $path,
                'disk' => $disk,
                'staged_at' => $now,
            ], $chunk));
        }

        return count($paths);
    }

    /**
     * Delete the files whose grace period is over, oldest first, retrying the failures whose backoff is over and that
     * have attempts left.
     *
     * @param  int|null  $after  Grace period in seconds; defaults to `rm-q.after` (1 day when invalid).
     * @param  int|null  $limit  Maximum number of files to process.
     *
     * @throws RmqException When called inside a transaction, or with invalid arguments or config.
     */
    public function deleteAll(?int $after = null, ?int $limit = null): DeletionReport
    {
        if ($limit !== null && $limit < 1) {
            throw RmqException::invalidArgument('limit', 'an integer greater than or equal to 1');
        }

        $cutoff = $this->cutoff($after);

        if ($this->insideTransaction()) {
            throw RmqException::insideTransaction();
        }

        $chunk = $this->setting('chunk', 1);
        $maxAttempts = $this->setting('max_attempts', 1);
        $backoff = $this->backoff();
        $remaining = $limit ?? PHP_INT_MAX;
        $lastId = 0;
        $deleted = 0;
        $failed = 0;
        $exhausted = 0;

        while ($remaining > 0) {
            // The write connection: a lagging read replica could return rows that are already gone.
            $files = $this->deletable(RmqFile::query()->useWritePdo(), $cutoff, $maxAttempts, Date::now('UTC'))
                ->where('id', '>', $lastId)
                ->orderBy('id')
                ->limit(min($chunk, $remaining))
                ->get(['id', 'path', 'disk', 'attempts']);

            if ($files->isEmpty()) {
                break;
            }

            foreach ($files as $file) {
                $lastId = $file->id;
                $remaining--;

                $attempts = $file->attempts + 1;
                $nextAttemptAt = $attempts < $maxAttempts
                    ? Date::now('UTC')->addSeconds($backoff[min($attempts, count($backoff)) - 1])
                    : null;

                // Counted before deleting: a file whose deletion kills the run still uses up an attempt, and waits.
                $counted = RmqFile::query()
                    ->whereKey($file->id)
                    ->increment('attempts', 1, ['next_attempt_at' => $nextAttemptAt]);

                if ($counted === 0) {
                    continue; // Removed meanwhile.
                }

                $error = $this->deleteFile($file);

                if ($error === null) {
                    RmqFile::query()->whereKey($file->id)->delete();
                    $deleted++;

                    continue;
                }

                RmqFile::query()->whereKey($file->id)->update(['error' => $error]);

                $failed++;
                $exhausted += (int) ($nextAttemptAt === null);

                Event::dispatch(new FileDeletionFailed(
                    $file->id,
                    $file->path,
                    $file->disk,
                    $error,
                    $attempts,
                    $nextAttemptAt !== null,
                    $nextAttemptAt?->setTimezone(date_default_timezone_get()),
                ));
            }
        }

        $report = new DeletionReport($deleted, $failed, $exhausted);

        Event::dispatch(new StagedFilesProcessed($report));

        return $report;
    }

    /**
     * How many files deleteAll() would process right now.
     *
     * @param  int|null  $after  Grace period in seconds; defaults to `rm-q.after` (1 day when invalid).
     *
     * @throws RmqException With invalid arguments or config.
     */
    public function countDeletable(?int $after = null): int
    {
        return $this->deletable(
            RmqFile::query(),
            $this->cutoff($after),
            $this->setting('max_attempts', 1),
            Date::now('UTC'),
        )->count();
    }

    /** Files staged at or before the cutoff, in UTC like `staged_at`, are past their grace period. */
    private function cutoff(?int $after): CarbonInterface
    {
        if ($after !== null && $after < 0) {
            throw RmqException::invalidArgument('after', 'an integer greater than or equal to 0');
        }

        return Date::now('UTC')->subSeconds($after ?? GracePeriod::fromConfig()->seconds);
    }

    /**
     * Files whose grace period and backoff are over, and that have attempts left.
     *
     * @param  Builder<RmqFile>  $query
     * @return Builder<RmqFile>
     */
    private function deletable(Builder $query, CarbonInterface $cutoff, int $maxAttempts, CarbonInterface $now): Builder
    {
        return $query
            ->where('staged_at', '<=', $cutoff)
            ->where('attempts', '<', $maxAttempts)
            ->where(fn (Builder $query) => $query
                ->whereNull('next_attempt_at')
                ->orWhere('next_attempt_at', '<=', $now));
    }

    /**
     * Seconds to wait after each failed attempt; the last delay repeats. Numeric strings are accepted.
     *
     * @return non-empty-list<int>
     */
    private function backoff(): array
    {
        $backoff = Config::get('rm-q.backoff');
        $delays = [];

        foreach (is_array($backoff) && array_is_list($backoff) ? $backoff : [] as $delay) {
            if (is_string($delay) && ctype_digit($delay)) {
                $delay = (int) $delay;
            }

            if (! is_int($delay) || $delay < 0) {
                $delays = [];

                break;
            }

            $delays[] = $delay;
        }

        if ($delays === []) {
            throw RmqException::invalidConfig('rm-q.backoff', 'a non-empty list of seconds, each an integer greater than or equal to 0');
        }

        return $delays;
    }

    /** @return string|null Why the file could not be deleted, or null once it is gone. */
    private function deleteFile(RmqFile $file): ?string
    {
        $violation = Paths::violation($file->path);

        if ($violation !== null) {
            return "The path {$violation}";
        }

        try {
            // Any Filesystem implementation: custom drivers and Storage::set() need not be FilesystemAdapters.
            $disk = app(FilesystemFactory::class)->disk($file->disk);

            $unavailable = $this->unavailableStorage($file, $disk);

            if ($unavailable !== null) {
                return $unavailable;
            }

            if ($disk instanceof FilesystemAdapter) {
                // Flysystem throws with the reason, whatever the disk's `throw` option says.
                $disk->getDriver()->delete($file->path);

                return null; // A missing file counts as deleted: local storage was checked above.
            }

            return $disk->delete($file->path) ? null : 'The disk could not delete the file';
        } catch (Throwable $e) {
            return Str::limit($e->getMessage(), 1000);
        }
    }

    /**
     * Why a local disk can't tell whether the file is missing, or null when it can. An unmounted volume looks like an
     * empty directory, or no directory at all: a missing parent directory means the file is out of reach, not gone.
     * Other disks, like S3, have no directories of their own.
     */
    private function unavailableStorage(RmqFile $file, Filesystem $disk): ?string
    {
        if (! $disk instanceof FilesystemAdapter || ! Paths::isLocalDisk($file->disk)) {
            return null;
        }

        // The resolved disk's root, not the configured one: Storage::fake() roots the disk elsewhere.
        $root = rtrim($disk->path(''), '/\\');

        if ($root === '') {
            return null; // Built by hand without a root: nothing to check against.
        }

        $parent = dirname($disk->path($file->path));

        clearstatcache();

        return match (true) {
            ! is_dir($root) => "The disk root [{$root}] does not exist: the storage may be unavailable",
            ! is_dir($parent) => "The parent directory [{$parent}] does not exist: the storage may be unavailable",
            default => null,
        };
    }

    /** An integer `rm-q` setting of at least $min; numeric strings are accepted. */
    private function setting(string $key, int $min): int
    {
        $value = Config::get("rm-q.{$key}");

        if (is_string($value) && ctype_digit($value)) {
            $value = (int) $value;
        }

        if (! is_int($value) || $value < $min) {
            throw RmqException::invalidConfig("rm-q.{$key}", "an integer greater than or equal to {$min}");
        }

        return $value;
    }

    /** Whether a transaction is open on the rm-q connection, ignoring the wrapping transaction of RefreshDatabase. */
    private function insideTransaction(): bool
    {
        $connection = (new RmqFile)->getConnection();

        if ($connection->transactionLevel() === 0) {
            return false;
        }

        /** @var DatabaseTransactionsManager $transactions */
        $transactions = app('db.transactions');

        // RefreshDatabase's manager leaves its wrapping transaction out of the applicable ones.
        return $transactions->callbackApplicableTransactions()->contains(
            fn (DatabaseTransactionRecord $transaction) => $transaction->connection === $connection->getName(),
        );
    }
}
