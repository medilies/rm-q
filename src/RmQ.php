<?php

namespace Medilies\RmQ;

use Closure;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Medilies\RmQ\Enums\Status;
use Medilies\RmQ\Events\FileDeletionFailed;
use Medilies\RmQ\Events\StagedFilesProcessed;
use Medilies\RmQ\Exceptions\RmqException;
use Medilies\RmQ\Models\RmqFile;
use Medilies\RmQ\Support\Outcome;
use Medilies\RmQ\Support\Paths;
use Medilies\RmQ\Support\Settings;
use Stringable;
use Symfony\Component\Uid\Ulid;
use Throwable;

/**
 * Bound as a scoped singleton: one instance per request (Octane) or job (queue worker).
 *
 * The database row is the source of truth: staging is a plain INSERT that joins whatever transaction is open
 * on the rm-q connection, and only committed rows are ever swept. Sweeps claim rows atomically, so each row is
 * processed by exactly one sweep.
 */
class RmQ
{
    /** 6 columns per row: stays well below SQL Server's 2100 bindings limit. */
    private const INSERT_CHUNK = 150;

    private readonly string $instance;

    /** Whether this instance staged rows that no sweep has picked up yet. */
    private bool $pending = false;

    /** The event dispatcher and disks are resolved at call time, so fakes work whenever they're installed. */
    public function __construct()
    {
        $this->instance = (string) new Ulid;
    }

    public function instanceId(): string
    {
        return $this->instance;
    }

    /**
     * Stage files for deletion. The rows join (and roll back with) any transaction open on the rm-q connection.
     *
     * @param  string|Stringable|iterable<mixed>  $paths  Absolute local paths, or paths on $disk.
     * @return int The number of staged paths (duplicates are staged once).
     *
     * @throws RmqException When a path or the disk is invalid.
     */
    public function stage(string|Stringable|iterable $paths, ?string $disk = null): int
    {
        $paths = Paths::normalize($paths, $disk);

        if ($paths === []) {
            return 0;
        }

        $now = Date::now();

        foreach (array_chunk($paths, self::INSERT_CHUNK) as $chunk) {
            RmqFile::query()->insert(array_map(fn (string $path): array => [
                'path' => $path,
                'disk' => $disk,
                'instance' => $this->instance,
                'status' => Status::Staged->value,
                'attempts' => 0,
                'staged_at' => $now,
            ], $chunk));
        }

        $this->pending = true;

        return count($paths);
    }

    /**
     * Run the callback in a transaction on the rm-q connection.
     *
     * @template TReturn
     *
     * @param  Closure(Connection): TReturn  $callback
     * @return TReturn
     */
    public function transaction(Closure $callback, int $attempts = 1): mixed
    {
        if ($attempts < 1) {
            throw RmqException::invalidArgument('attempts', 'an integer greater than or equal to 1');
        }

        return $this->connection()->transaction($callback, $attempts);
    }

    /**
     * Delete the files staged by this instance (this request or job).
     *
     * Inside a transaction, the deletion is deferred until the outermost commit, and dropped on rollback.
     */
    public function delete(): void
    {
        if (! $this->pending) {
            return;
        }

        $this->whenNoTransaction(function (): void {
            if (! $this->pending) {
                return;
            }

            $report = new DeletionReport;

            $this->sweep(fn (Builder $query) => $query
                ->where('instance', $this->instance)
                ->where('status', Status::Staged->value), $report);

            $this->pending = false;

            $this->finish($report);
        });
    }

    /**
     * Delete every staged file whose grace period is over, reclaim abandoned claims, and retry failures.
     *
     * @param  int|null  $after  Grace period in seconds; defaults to `rm-q.after`.
     * @param  int|null  $limit  Maximum number of rows to process.
     *
     * @throws RmqException When called inside a transaction, or with invalid arguments or config.
     */
    public function deleteAll(?int $after = null, ?int $limit = null): DeletionReport
    {
        if ($after !== null && $after < 0) {
            throw RmqException::invalidArgument('after', 'an integer greater than or equal to 0');
        }

        if ($limit !== null && $limit < 1) {
            throw RmqException::invalidArgument('limit', 'an integer greater than or equal to 1');
        }

        if ($this->transactionPending()) {
            throw RmqException::insideTransaction();
        }

        $after ??= Settings::int('after');
        $start = Date::now();
        $report = new DeletionReport($limit);

        // Rows whose sweep died mid-chunk.
        $this->sweep(fn (Builder $query) => $query
            ->where('status', Status::Processing->value)
            ->where('claimed_at', '<=', $start->copy()->subSeconds(Settings::int('claim_timeout'))), $report);

        // Staged rows past their grace period.
        $this->sweep(fn (Builder $query) => $query
            ->where('status', Status::Staged->value)
            ->where('staged_at', '<=', $start->copy()->subSeconds($after)), $report);

        // Retries, at most once per run.
        $this->sweep(fn (Builder $query) => $query
            ->where('status', Status::Failed->value)
            ->where('attempts', '<', Settings::int('max_attempts', 1))
            ->where('processed_at', '<', $start), $report);

        $this->finish($report);

        return $report;
    }

    /**
     * Claim eligible rows chunk by chunk, delete their files, and record the outcomes.
     *
     * @param  Closure(Builder<RmqFile>): Builder<RmqFile>  $eligible
     */
    private function sweep(Closure $eligible, DeletionReport $report): void
    {
        $token = (string) new Ulid;
        $chunk = Settings::int('chunk', 1);
        $maxAttempts = Settings::int('max_attempts', 1);

        while (! $report->limitReached()) {
            // The write connection: a lagging read replica could hide freshly staged or claimed rows.
            $ids = $eligible(RmqFile::query()->useWritePdo())
                ->limit($report->nextChunkSize($chunk))
                ->pluck('id')
                ->all();

            if ($ids === []) {
                return;
            }

            // Re-applying the eligibility predicate makes the claim atomic between concurrent sweeps.
            $claimed = $eligible(RmqFile::query())
                ->whereIntegerInRaw('id', $ids)
                ->increment('attempts', 1, [
                    'status' => Status::Processing->value,
                    'claimed_by' => $token,
                    'claimed_at' => Date::now(),
                ]);

            if ($claimed === 0) {
                continue; // Lost to a concurrent sweep.
            }

            $files = RmqFile::query()->useWritePdo()
                ->whereIntegerInRaw('id', $ids)
                ->where('claimed_by', $token)
                ->get(['id', 'path', 'disk', 'attempts']);

            $deleted = [];
            $failed = [];

            foreach ($files as $file) {
                $outcome = $this->deleteFile($file);

                if ($outcome->deleted) {
                    $deleted[] = $file->id;

                    continue;
                }

                $failed[$outcome->error][] = $file->id;

                $willRetry = $file->attempts < $maxAttempts;
                $report->recordFailure($file->path, $file->disk, $outcome->error, ! $willRetry);
                Event::dispatch(new FileDeletionFailed(
                    $file->id, $file->path, $file->disk, $outcome->error, $file->attempts, $willRetry,
                ));
            }

            $now = Date::now();

            // Conditional on the claim: a row reclaimed by another sweep meanwhile is left alone.
            if ($deleted !== []) {
                RmqFile::query()
                    ->whereIntegerInRaw('id', $deleted)
                    ->where('claimed_by', $token)
                    ->update(['status' => Status::Deleted->value, 'processed_at' => $now, 'error' => null]);

                $report->deleted += count($deleted);
            }

            foreach ($failed as $error => $failedIds) {
                RmqFile::query()
                    ->whereIntegerInRaw('id', $failedIds)
                    ->where('claimed_by', $token)
                    ->update(['status' => Status::Failed->value, 'processed_at' => $now, 'error' => (string) $error]);
            }
        }
    }

    private function deleteFile(RmqFile $file): Outcome
    {
        $violation = Paths::violation($file->path, $file->disk);

        if ($violation !== null) {
            return Outcome::failed("The path {$violation}");
        }

        try {
            if ($file->disk === null) {
                return $this->deleteLocalFile($file->path);
            }

            // Any Filesystem implementation: custom drivers and Storage::set() need not be FilesystemAdapters.
            $disk = app(FilesystemFactory::class)->disk($file->disk);

            if ($disk instanceof FilesystemAdapter) {
                // Flysystem throws with the reason, whatever the disk's `throw` option says.
                $disk->getDriver()->delete($file->path);

                return Outcome::deleted(); // A missing file counts as deleted, like on S3.
            }

            return $disk->delete($file->path) ? Outcome::deleted() : Outcome::failed('The disk could not delete the file');
        } catch (Throwable $e) {
            return Outcome::failed(Str::limit($e->getMessage(), 1000));
        }
    }

    private function deleteLocalFile(string $path): Outcome
    {
        clearstatcache(true, $path);

        if (! file_exists($path) && ! is_link($path)) {
            return Outcome::deleted(); // Already missing.
        }

        if (is_dir($path) && ! is_link($path)) {
            return Outcome::failed('The path is a directory');
        }

        // Laravel's error handler swallows warnings, even suppressed ones, so error_get_last() stays empty.
        $error = null;

        set_error_handler(function (int $level, string $message) use (&$error): bool {
            $error = $message;

            return true;
        });

        try {
            $deleted = unlink($path); // For a symlink, only the link is removed.
        } finally {
            restore_error_handler();
        }

        if ($deleted) {
            return Outcome::deleted();
        }

        clearstatcache(true, $path);

        return file_exists($path) || is_link($path)
            ? Outcome::failed($error ?? 'unlink() failed')
            : Outcome::deleted(); // Removed concurrently.
    }

    private function finish(DeletionReport $report): void
    {
        if ($report->failed > 0) {
            Log::warning("rm-q: {$report->failed} staged file(s) could not be deleted.", [
                'exhausted' => $report->exhausted,
                'sample' => $report->failureSample,
            ]);
        }

        Event::dispatch(new StagedFilesProcessed($report));
    }

    /**
     * Run the work now when no transaction is pending, otherwise after the outermost commit (dropped on rollback).
     *
     * Laravel's after-commit callbacks are not connection-scoped: a callback may fire when another connection
     * commits, so a deferred callback checks again before running.
     */
    private function whenNoTransaction(Closure $work): void
    {
        if (! $this->transactionPending()) {
            $work(); // Synchronous: exceptions reach the caller.

            return;
        }

        $this->connection()->afterCommit(function () use ($work): void {
            try {
                $this->whenNoTransaction($work);
            } catch (Throwable $e) {
                report($e); // Never throw out of an already committed DB::transaction().
            }
        });
    }

    /** Respects the wrapping transaction of RefreshDatabase. */
    private function transactionPending(): bool
    {
        $pending = true;

        $this->connection()->afterCommit(function () use (&$pending): void {
            $pending = false;
        });

        return $pending;
    }

    private function connection(): Connection
    {
        return DB::connection(Settings::connection());
    }
}
