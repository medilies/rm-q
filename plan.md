# rm-q — production-readiness plan & specs

Living document: tick the checklists as work lands. Branch: `production-readiness`, one commit per phase,
never pushed without asking, no Claude attribution in commit messages.

## 1. Context

`medilies/rm-q` queues file deletions in a DB table so they roll back with DB transactions, then deletes the files
later (per request via `RmQ::delete()` / middleware, or in bulk via `rm-q:delete` after a grace period).

The package is **unreleased** (no tags, not on Packagist, last commit 2024‑09), so API and schema can still change
freely. The uncommitted WIP on `main` (moves the `hasFilesInDb` guard to `delete()`, `transaction()` returns the
callback value, adds a `disk` column) is folded into Phase 2.

A review found bugs that cause the very data loss the package promises to prevent, plus gaps in lifecycle, scale,
concurrency, compatibility (only EOL Laravel 10/11 supported) and docs. Framework internals were checked against
the Laravel 11–13, Octane and testbench-core sources. Goal: a correct, tested, documented **v1.0.0**.

### Decisions

| Topic | Decision |
|---|---|
| Laravel support | **12 + 13** only, PHP `^8.2` (L13 itself needs 8.3) |
| Storage disks | Supported: `stage($paths, disk: 's3')`, deleted through Flysystem |
| File already missing at delete time | Row ends as **Deleted** (idempotent) |
| Staging | **Always a DB insert inside the caller's transaction**. The middleware's in-memory mode and `RmQ::transaction()` batching are removed |

## 2. What's wrong today

| # | Sev. | Problem (verified) | Where |
|---|---|---|---|
| 1 | Crit | `RmqException` declares namespace `…\Facades` but lives in `src/Exceptions/`, so `new RmqException` is a "Class not found" fatal. Tests mask it (they catch `Throwable` or only assert a 500). | `src/Exceptions/RmqException.php` |
| 2 | Crit | HEAD: `deleteAll()` only runs if the same instance staged something, so `rm-q:delete` in a fresh process does nothing. Tests mask it. | `src/RmQ.php` |
| 3 | Crit | Middleware mode: an `RmQ::transaction` nested in an outer transaction moves its paths to memory when the inner savepoint is released. If the outer then rolls back, the route pipeline turns it into a 500, the middleware still runs and **deletes the files**. An inner failure caught by the outer also wipes the outer's buffer (leak). | `RmQ::transaction()`, `RmqMiddleware` |
| 4 | Crit | The trait stages on `deleted`, which also fires on **soft delete**, so files are destroyed for models that can still be restored. | `src/Models/RmqStageOnDeleteTrait.php` |
| 5 | Crit | The README stages a disk-relative path (`$image->path`), but deletion is `unlink()`, which resolves it against the CWD (`public/` under FPM). It can delete a different file. | `README.md`, `RmQ` |
| 6 | High | `singleton`: `queue:work` reuses one instance (ULID, flags, buffers) across jobs. `arrayStorage` is never cleared, so calling `RmQ::delete()` as the README shows, plus the middleware in the same request, processes it twice and writes duplicate FAILED rows. (Octane is mostly isolated by its per-request sandbox.) | `RmqServiceProvider`, `RmQ` |
| 7 | High | Overlapping sweeps (multi-server cron, long runs, `delete()` racing `deleteAll()` with a small `after`) process the same row twice. Worst case: a stale run unlinks a **new file re-uploaded at the same path**. | `RmQ::performDeleteUsingDb` |
| 8 | High | Unbounded work: `->get()` loads the whole backlog, and unbounded `whereIn`/`insert` exceed bind limits (SQL Server 2 100, old SQLite 999, Postgres 65 535). No indexes. `path` is VARCHAR(255). | `RmQ`, migration |
| 9 | High | `staged_at` comes from the DB's `CURRENT_TIMESTAMP`, but the grace filter uses PHP `now()`. When the app timezone differs from the DB session timezone, the grace period shrinks by the offset. It also breaks `travel()` in tests. | migration, `RmqFile` |
| 10 | High | Rollback protection only holds on the default connection. | `RmqFile`, `RmQ` |
| 11 | High | The trait test is disabled. `OrchestraTestCase::getEnvironmentSetUp()` instantiates `Picture`/`RmqFile` before providers boot, so the `deleted` listener lands on no dispatcher or a stale one. The test "deleteAll() after reaching the after config" calls `delete()`. | `tests/` |
| 12 | Med | Non-string paths are silently dropped and `''` is accepted (a test stages it). A string `after` from `env()` throws `TypeError`. No failure reason is stored. `rm-q:cleanup` is one unbounded DELETE with no retention. Deletion runs before the response is sent and logs by string concatenation. `withinTransaction()` is public. | various |
| 13 | Tooling | Only EOL Laravel 10/11. CI pins Carbon 2 (breaks L12) and has no `pull_request` trigger. Deprecated `--no-suggest --dev`. Dead `Database\Factories` autoload. Broken workbench and `clear/build/start` scripts. README and issue template link to a `SECURITY.md` that doesn't exist. `src/RmQ.php` is mode 755. The meme image ships in the dist archive. | `composer.json`, `.github/` |

## 3. Specs

### 3.1 Principles

1. **The DB row is the source of truth.** No paths are held in memory. `stage()` is a plain INSERT, so it joins
   whatever transaction is open; rollback, savepoints and deadlock retries are handled by the database.
2. **Only committed rows ever touch files.** Deletion runs immediately when no transaction is open, otherwise after
   the outermost commit, and is dropped on rollback.
3. **Each row is processed by exactly one sweeper**, through an atomic claim. Every status write is conditional on
   the claim token.
4. **Correctness doesn't depend on the container lifecycle.** The only in-memory state is a ULID and one boolean.

### 3.2 Public API

```php
use Medilies\RmQ\Facades\RmQ;

RmQ::stage(string|Stringable|iterable $paths, ?string $disk = null): int  // null disk = absolute local path
RmQ::transaction(Closure $callback, int $attempts = 1): mixed  // = DB::connection(rm-q.connection)->transaction()
RmQ::delete(): void            // sweep rows staged by this request/job; deferred to after the outermost commit
RmQ::deleteAll(?int $after = null, ?int $limit = null): DeletionReport  // throws RmqException inside a transaction
RmQ::countDeletable(?int $after = null): array  // rows deleteAll() would process now: abandoned / staged / retries
RmQ::instanceId(): string
```

The facade docblock mirrors this (`transaction` as `@template T` / `Closure(): T` / `T`). Removed:
`usingMiddleware()`, `withinTransaction()`, `getStorage()`.

### 3.3 Statuses — `Medilies\RmQ\Enums\Status: int` (cast on the model)

| Value | Case | Meaning |
|---|---|---|
| 0 | `Staged` | Waiting for a sweep (grace period for `deleteAll`, immediate for `delete()`) |
| 1 | `Deleted` | File is gone — deleted by us, or already missing |
| 2 | `Failed` | Last attempt failed; retried by `deleteAll()` while `attempts < max_attempts`, then "exhausted" |
| 3 | `Processing` | Claimed by a sweeper; reclaimed after `claim_timeout` if that sweeper died |

`label()` returns a human label; unknown DB values render as `Unknown (n)` in `rm-q:stats` (never throw).

### 3.4 `src/RmQ.php`

> Implemented in Phase 2; where the code differs from the sketches below, see "Deviations from the specs" in the Phase 2 notes (section 7).

The binding is `$this->app->scoped(RmQ::class)`, so there is a fresh instance and ULID per request (Octane) and per
job (`queue:work`). Config is read at call time, never in the constructor, so it respects Octane's config sandbox.

```php
class RmQ // not final: Facade::shouldReceive() / Mockery
{
    private readonly string $instance;   // (string) new Ulid — 26 chars, matches $table->ulid()
    private bool $pending = false;       // this instance staged rows that no sweep has picked up yet

    public function __construct(
        private readonly \Illuminate\Contracts\Filesystem\Factory $files,
        private readonly \Illuminate\Contracts\Events\Dispatcher $events,
    ) {
        $this->instance = (string) new Ulid;
    }

    public function stage(string|Stringable|iterable $paths, ?string $disk = null): int
    {
        $paths = Paths::normalize($paths, $disk);          // throws RmqException (rules in 3.6)
        $now = Date::now();                                 // PHP time: no DB/app timezone skew (#9)

        foreach (array_chunk($paths, 150) as $chunk) {      // 6 columns × 150 rows = 900 bindings (#8)
            RmqFile::query()->insert(array_map(fn (string $path) => [
                'path' => $path, 'disk' => $disk, 'instance' => $this->instance,
                'status' => Status::Staged->value, 'attempts' => 0, 'staged_at' => $now,
            ], $chunk));
        }

        $this->pending = $this->pending || $paths !== [];

        return count($paths);
    }

    public function transaction(Closure $callback, int $attempts = 1): mixed
    {
        return $this->connection()->transaction($callback, $attempts);
    }

    public function delete(): void
    {
        if (! $this->pending) {
            return;                                         // zero queries when nothing was staged
        }

        $this->whenNoTransaction(function (): void {
            if (! $this->pending) {
                return;                                     // an earlier deferred call already swept
            }
            $this->sweep(fn (Builder $q) => $q->where('instance', $this->instance)
                ->where('status', Status::Staged->value), new DeletionReport);
            $this->pending = false;                         // reset only once a sweep actually ran
        });
    }

    public function deleteAll(?int $after = null, ?int $limit = null): DeletionReport
    {
        if ($this->transactionPending()) {
            throw RmqException::insideTransaction();
        }

        $after ??= Settings::int('after');
        $start = Date::now();
        $report = new DeletionReport($limit);

        // 1. abandoned claims (sweeper died mid-chunk)
        $this->sweep(fn ($q) => $q->where('status', Status::Processing->value)
            ->where('claimed_at', '<=', $start->copy()->subSeconds(Settings::int('claim_timeout'))), $report);
        // 2. staged rows past the grace period
        $this->sweep(fn ($q) => $q->where('status', Status::Staged->value)
            ->where('staged_at', '<=', $start->copy()->subSeconds($after)), $report);
        // 3. retries — at most once per run
        $this->sweep(fn ($q) => $q->where('status', Status::Failed->value)
            ->where('attempts', '<', Settings::int('max_attempts'))
            ->where('processed_at', '<', $start), $report);

        $this->events->dispatch(new StagedFilesProcessed($report));

        return $report;
    }
}
```

**Deferral** — Laravel's `afterCommit()` runs the callback immediately when no (non-test) transaction is open, and
otherwise after the outermost commit; it drops the callback on rollback, including a rolled-back savepoint while the
outer transaction continues. It is **not connection-scoped**: it attaches to the most recent pending transaction on
*any* connection. So:

```php
/** Run now if no transaction is pending; else after the outermost COMMIT; dropped on ROLLBACK. */
private function whenNoTransaction(Closure $work): void
{
    $inline = true;
    $this->connection()->afterCommit(function () use (&$inline, $work): void {
        if ($inline) {
            $work();                                       // synchronous: exceptions reach the caller
            return;
        }
        try {
            $this->whenNoTransaction($work);               // fired by SOME root commit: re-check
        } catch (Throwable $e) {
            report($e);                                    // never throw out of a committed DB::transaction()
        }
    });
    $inline = false;
}

private function transactionPending(): bool               // test-aware under RefreshDatabase
{
    $pending = true;
    $this->connection()->afterCommit(function () use (&$pending): void { $pending = false; });
    return $pending;                                       // the queued no-op is harmless
}
```

**Sweep (atomic claim):**

```php
private function sweep(Closure $eligible, DeletionReport $report): void
{
    $token = (string) new Ulid;

    while (! $report->limitReached()) {
        $ids = $eligible(RmqFile::query()->useWritePdo())          // write PDO: read replicas lag
            ->limit($report->nextChunkSize(Settings::int('chunk')))
            ->pluck('id')->all();
        if ($ids === []) {
            return;
        }

        $claimed = $eligible(RmqFile::query())                     // predicate re-applied => atomic claim
            ->whereIntegerInRaw('id', $ids)                        // ints inlined: no bindings
            ->update([
                'status' => Status::Processing->value, 'claimed_by' => $token,
                'claimed_at' => Date::now(), 'attempts' => new Expression('attempts + 1'),
            ]);
        if ($claimed === 0) {
            continue;                                              // lost to a concurrent sweeper
        }

        $rows = RmqFile::query()->useWritePdo()
            ->whereIntegerInRaw('id', $ids)->where('claimed_by', $token)
            ->get(['id', 'path', 'disk', 'attempts']);

        $outcomes = $rows->mapWithKeys(fn (RmqFile $file) => [$file->id => $this->deleteOne($file)]);

        // Grouped: one UPDATE for Deleted, one per distinct error for Failed,
        // always `WHERE id IN (...) AND claimed_by = $token`. Sets status, processed_at, error.
        $this->persistOutcomes($outcomes, $token);
        $report->record($rows, $outcomes);                         // also dispatches FileDeletionFailed
    }
}
```

**Deleting one file:**

```php
private function deleteOne(RmqFile $file): Outcome
{
    try {
        if ($file->disk === null) {
            return $this->deleteLocal($file->path);                // + allowed_directories re-check
        }
        $disk = $this->files->disk($file->disk);                   // unknown disk => exception => Failed
        if ($disk instanceof FilesystemAdapter) {
            $disk->getDriver()->delete($file->path);               // keeps the reason whatever 'throw' is set to
            return Outcome::deleted();                             // Flysystem/S3: missing == deleted
        }
        return $disk->delete($file->path) ? Outcome::deleted() : Outcome::failed('delete() returned false');
    } catch (Throwable $e) {
        return Outcome::failed(Str::limit($e->getMessage(), 1000));
    }
}

private function deleteLocal(string $path): Outcome
{
    clearstatcache(true, $path);
    if (! file_exists($path) && ! is_link($path)) {
        return Outcome::deleted();                                 // already missing (decision)
    }
    if (is_dir($path) && ! is_link($path)) {
        return Outcome::failed('Path is a directory');
    }
    error_clear_last();
    if (@unlink($path)) {
        return Outcome::deleted();                                 // a symlink: removes the link, not the target
    }
    clearstatcache(true, $path);
    return file_exists($path) || is_link($path)
        ? Outcome::failed(error_get_last()['message'] ?? 'unlink() failed')
        : Outcome::deleted();                                      // raced with another process
}
```

### 3.5 Supporting classes

- `src/DeletionReport.php`: counters `deleted`, `failed` (this run), `exhausted` (failures that just reached
  `max_attempts`); `?int $limit`, `processed()`, `limitReached()`, `nextChunkSize(int $chunk)`, `record()`.
- `src/Support/Outcome.php`: a small value object (`deleted()` / `failed(string $error)`).
- `src/Support/Settings.php`: reads `rm-q.*` config; ints must be ≥ 0, numeric strings are accepted (values from
  `env()`), anything else throws `RmqException::invalidConfig($key)`.
- `src/Exceptions/RmqException.php`: `namespace Medilies\RmQ\Exceptions`, named constructors `invalidPath()`,
  `unknownDisk()`, `invalidConfig()`, `insideTransaction()`.
- `src/Events/FileDeletionFailed.php` (`id, path, ?disk, error, attempts, willRetry`) and
  `src/Events/StagedFilesProcessed.php` (`DeletionReport`), once per sweep run. There is deliberately no staging
  event (it would fire inside uncommitted transactions) and no per-file success event.
- After a sweep with failures, log one `Log::warning('rm-q: N staged file(s) could not be deleted', [...])` with the
  exhausted count and a sample of 5 `(path, disk, error)`.

### 3.6 Path rules — `Medilies\RmQ\Support\Paths::normalize()`

Violations throw `RmqException` at stage time, inside the caller's transaction, so a bad path aborts the business
change instead of leaking a file.

- Each item must be a `string` or `Stringable`, and non-empty.
- No NUL byte, and no `scheme://` prefix (no stream wrappers such as `phar://` or `s3://`).
- No `..` segment (`/` or `\` separated).
- `disk === null`: the path must be absolute — `/…`, `C:\…` / `C:/…`, or `\\host\…`.
- `disk !== null`: `config("filesystems.disks.{$disk}")` must exist.
- Duplicates within one call are removed with `array_values(array_unique(...))`, not array keys, because numeric
  strings would become int keys.
- Optional `rm-q.allowed_directories` (absolute dirs, default `null` = unrestricted). Local paths must live under one
  of them. Compare with separators normalised to `/` and a trailing separator, which is sound because `..` is
  rejected. Checked at stage time **and** sweep time, since rows can be inserted by other means; at sweep time a
  violation means Failed.

### 3.7 Schema (edit `database/migrations/2019_04_19_100000_create_rm_q_table.php` in place)

```php
return new class extends Migration
{
    public function getConnection(): ?string { return config('rm-q.connection'); }

    public function up(): void
    {
        Schema::create(config('rm-q.table', 'rm_q_files'), function (Blueprint $table) {
            $table->id();
            $table->text('path');                               // absolute path or disk key — no 255 cap
            $table->string('disk', 64)->nullable();             // null => absolute local path
            $table->unsignedTinyInteger('status')->default(0);  // Status enum
            $table->ulid('instance');                           // request/job that staged it
            $table->dateTime('staged_at');                      // PHP-written: no tz conversion, no 2038
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->ulid('claimed_by')->nullable();
            $table->dateTime('claimed_at')->nullable();
            $table->dateTime('processed_at')->nullable();
            $table->text('error')->nullable();
            $table->index(['status', 'staged_at']);             // deleteAll passes, stats, cleanup
            $table->index(['instance', 'status']);              // delete()
        });
    }

    public function down(): void { Schema::dropIfExists(config('rm-q.table', 'rm_q_files')); }
};
```

Removed: the `Schema::hasTable()` guard (it hides schema drift) and `deleted_at` (redundant, and it looks like
SoftDeletes). `RmqFile` gets the `Status` cast and datetime casts, `getTable()` / `getConnectionName()` from config,
and stays `final`.

### 3.8 Config — `config/rm-q.php`

```php
return [
    // Connection that holds the rm-q table. It MUST be the connection your business transactions use,
    // otherwise staging will not roll back with them. null = default connection.
    'connection' => env('RM_Q_CONNECTION'),

    'table' => 'rm_q_files',

    // Grace period (seconds) before rm-q:delete / RmQ::deleteAll() touches a staged file.
    // Keep it longer than your longest request or job. Default: 1 day.
    'after' => env('RM_Q_AFTER', 60 * 60 * 24),

    'chunk' => 500,                // rows claimed and processed per batch
    'max_attempts' => 3,           // a Failed row is retried until it reaches this many attempts
    'claim_timeout' => 60 * 60,    // seconds before a Processing row counts as abandoned
    'cleanup_after_days' => 30,    // rm-q:cleanup keeps processed rows this long

    // Optional allowlist of absolute directories that disk-less paths must live under. null = no restriction.
    'allowed_directories' => null,
];
```

### 3.9 Trait — `RmqStageOnDeleteTrait`

```php
public static function bootRmqStageOnDeleteTrait(): void
{
    static::deleting(function (self $model): void {
        if ($model->rmqIsSoftDeleting()) {
            return;
        }
        $model->rmqAssertColumnsLoaded();        // LogicException on partial selects (silent leak otherwise)
    });

    static::deleted(function (self $model): void {
        if ($model->rmqIsSoftDeleting()) {
            return;                              // soft delete keeps the files; forceDelete() stages them
        }
        foreach ($model->rmqPathsByDisk() as [$disk, $paths]) {
            app(RmQ::class)->stage($paths, $disk);
        }
    });
}

/** @return array<int|string, string> ['path', 'thumb'] (uses rmqDisk()) or ['path' => 's3'] (column => disk) */
abstract public function listFilePathColumns(): array;

public function rmqDisk(): ?string { return null; }

private function rmqIsSoftDeleting(): bool
{
    return method_exists($this, 'isForceDeleting') && ! $this->isForceDeleting();
}
```

- Values are read with `getRawOriginal($column)`, so accessors, casts and unsaved edits don't change what gets staged.
  `null` and `''` are skipped.
- Documented bypasses: `deleteQuietly()`, query-builder mass deletes, and DB cascades.

### 3.10 Middleware

```php
final class RmqMiddleware // alias "rm-q", registered via callAfterResolving('router', ...)
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        try {
            app(RmQ::class)->delete();           // after the response is sent; also runs when the controller threw
        } catch (Throwable $e) {
            report($e);                          // Http\Kernel::terminate() has no try/catch
        }
    }
}

final class DeleteStagedFiles // Medilies\RmQ\Queue — optional job middleware
{
    public function handle(object $job, Closure $next): mixed
    {
        try {
            return $next($job);
        } finally {
            try { app(RmQ::class)->delete(); } catch (Throwable $e) { report($e); }
        }
    }
}
```

### 3.11 Commands

| Command | Options | Behaviour |
|---|---|---|
| `rm-q:delete` | `--after=` `--limit=` `--dry-run` | Implements `Isolatable` (`--isolated`). Runs `deleteAll()`. Prints a table of Deleted / Failed (will retry) / Failed (exhausted) and the duration. `--dry-run` only counts eligible rows per pass and claims nothing. Exits `FAILURE` when a row exhausted its retries in this run, so `onFailure()` hooks fire once per stuck file. |
| `rm-q:cleanup` | `--days=` `--with-failed` | `limit(1000)->delete()` loop. Removes `Deleted` rows (plus exhausted `Failed` rows with `--with-failed`) whose `processed_at` is older than `--days` (default `cleanup_after_days`). Prints the count. |
| `rm-q:stats` | `--json` | Counts per status (`Unknown (n)` for unexpected values), oldest staged age, rows eligible now, abandoned claims, exhausted failures. |
| `rm-q:retry` | — | Sets exhausted `Failed` rows back to `Staged` with `attempts = 0` and `error = null`. Prints the count. |

Recommended scheduling, for the README:

```php
Schedule::command('rm-q:delete --isolated')->everyFifteenMinutes()->withoutOverlapping()->onOneServer();
Schedule::command('rm-q:cleanup')->daily()->onOneServer();
```

## 4. Phases & checklists

### Phase 1 — Toolchain on Laravel 12/13 ✅ (commit on `production-readiness`)

- [x] `composer.json`:
  - require: `php ^8.2`, `illuminate/{console,contracts,database,filesystem,http,support}: ^12.0||^13.0`,
    `symfony/uid: ^7.2||^8.0`.
  - require-dev: `orchestra/testbench ^10.0||^11.0`, `pestphp/pest` and `pestphp/pest-plugin-laravel ^3.0||^4.0`,
    `larastan/larastan ^3.0`, `phpstan/phpstan-deprecation-rules ^2.0`, `phpstan/phpstan-phpunit ^2.0`,
    `phpstan/extension-installer ^1.4`, `laravel/pint ^1.18`.
  - Dropped the arch plugin, explicit collision, the `Database\Factories` and `Workbench` autoload entries, and the
    `clear/build/start` scripts.
- [x] Deleted `workbench/`.
- [x] `phpunit.xml.dist` schema → `vendor/phpunit/phpunit/phpunit.xsd` (always matches the installed PHPUnit).
- [x] `phpstan.neon.dist`: now also analyses `tests/test-app`, so the trait is checked through `Picture`.
  `configDirectories` is moved to Phase 2, when `env()` lands in the config.
- [x] `RmqServiceProvider`: dropped the `publishesMigrations` fallback.
- [x] CI `run-tests.yml`: push + PR; PHP 8.2–8.5 × L12/L13 × lowest/stable; one Windows job; no Carbon pin;
  `--exclude-group=integration`.
- [x] CI `phpstan.yml`: PHP 8.4; `composer validate --strict`;
  `composer dump-autoload --optimize --strict-psr`; `pint --test` on PRs; PHPStan; runs on PRs too.
- [x] `.github/dependabot.yml` (github-actions).
- [x] All local checks green (see the Phase 1 notes in section 7).

### Phase 2 — Core rewrite (#1–#3, #6–#11) ✅

- [x] `src/RmQ.php` per 3.4, plus `Support/Paths`, `Support/Outcome`, `Support/Settings`, `DeletionReport`,
  `Enums/Status`. The deviations are listed in the Phase 2 notes.
- [x] CI `databases` job (moved from Phase 1): SQLite (in-memory and file), `mysql:8.4`, `mariadb:11.4`,
  `postgres:17` × L12/L13. It runs the full suite including `--group=integration`, with the database session
  timezone skewed to `+05:00`.
- [x] `phpstan.neon.dist`: `configDirectories: [%currentWorkingDirectory%/config]`.
- [x] `RmqServiceProvider`: `scoped` binding, `rm-q` middleware alias.
- [x] `RmqException` named constructors.
- [x] `RmqFile`: casts (`Status` enum, datetimes), config-driven table and connection.
- [x] Migration and config per 3.7 and 3.8.
- [x] Test harness (#11):
  - `tests/TestCase.php` handles config in `defineEnvironment()`. Migrations are registered with
    `load_migration_paths()` (Testbench), so both `RefreshDatabase` and `DatabaseMigrations` run them.
  - `tests/IntegrationTestCase.php` uses a SQLite file (or the configured server), so child processes share the
    database.
  - Helpers: `temporaryFiles()` / `temporaryDirectory()` (cleaned up after each test), `newLifecycle()`,
    `brokenDisk()`, `stagedRow()`.
  - No `testbench.yaml` needed: the CLI finds the package through package discovery and takes the database from
    env vars.
- [x] Regression tests for #1–#3 and #6–#11 (see the Phase 2 notes).

### Phase 3 — Trait, middleware, commands, events (#4, #5, #12) ✅

- [x] Trait per 3.9, with the deviations listed in the Phase 3 notes.
- [x] Middleware per 3.10 (done in Phase 2).
- [x] Job middleware `Medilies\RmQ\Queue\DeleteStagedFiles`.
- [x] Commands per 3.11: `rm-q:delete` (`--after`, `--limit`, `--dry-run`, `Isolatable`, exit codes),
  `rm-q:cleanup` (`--days`, `--with-failed`, chunked), `rm-q:stats` (`--json`, details), new `rm-q:retry`.
- [x] Events and the failure log per 3.5 (done in Phase 2).
- [x] Regression tests for #4, #5, #12 and the commands.

### Phase 4 — Docs & release

- [ ] Rewrite the README:
  - Quick start with disks (`RmQ::stage($image->path, 'public')`), and how it works (stage → commit → sweep →
    record).
  - Guarantees and caveats:
    - The same connection is required.
    - `deleteQuietly()`, query-builder deletes and DB cascades bypass the trait.
    - Only stage files that the record owns exclusively.
    - A deterministic path can be reused before the sweep.
    - Never stage user input.
    - The middleware and `delete()` skip the grace period (rely on cron alone for critical files).
    - Per-tenant disks need per-tenant runs.
    - Versioned S3 buckets keep old versions.
  - The scheduling snippet, and the config / commands / events reference.
  - "One INSERT per `stage()` call — pass arrays to batch."
- [ ] `CHANGELOG.md` v1.0.0, `SECURITY.md`, `.gitattributes` export-ignore `concept-meme.webp`,
  `chmod 644 src/RmQ.php`.
- [ ] Release, only with a go-ahead (it's outward-facing): tag `v1.0.0`, GitHub release, Packagist submission and
  hook, un-comment the downloads badge.

## 5. Verification

**Every phase / CI:**

- `composer validate --strict`
- `composer dump-autoload -o --strict-psr`
- `vendor/bin/pint --test`
- `vendor/bin/phpstan` (level max)
- `vendor/bin/pest` on SQLite, then with `DB_CONNECTION=mysql|mariadb|pgsql`, including `--group=integration`
- one `composer update --prefer-lowest` run

**Regression tests (Pest), at least one per finding:**

- **#1 / #12 (inputs and config)**
  - Invalid inputs throw `RmqException`: empty, relative without a disk, `..`, NUL, `phar://`, unknown disk,
    outside `allowed_directories`.
  - `rm-q:stats` with a `status = 99` row succeeds.
  - A string `RM_Q_AFTER` is accepted.
- **#2 (fresh process)**
  - Seed rows with a foreign `instance`, `travel()` past `after`, run `artisan('rm-q:delete')`: the files are gone.
  - Integration: run `vendor/bin/testbench rm-q:delete` as a subprocess.
- **#3 (transactions)**
  - A route behind the middleware runs `DB::transaction(fn () => [RmQ::transaction(stage), throw])`: response 500,
    the file is still there, 0 rows.
  - An inner savepoint rolls back while the outer commits: only the outer's rows exist.
  - `delete()` inside a transaction: the file is still there inside the closure, gone after commit, and kept on
    rollback.
  - A deferred sweep failure is reported (`Exceptions::fake()`), and the transaction doesn't throw.
  - A nested commit on another connection doesn't trigger early deletion.
  - Integration: a deadlock message on attempt 1 with `attempts = 3` leaves exactly one set of rows.
- **#4 (soft deletes and the trait)**
  - On a SoftDeletes model, `delete()` stages nothing and `restore()` works afterwards.
  - `forceDelete()` stages; `deleteQuietly()` doesn't.
  - With an accessor on the path column, the raw value is staged.
  - A partial select throws.
- **#5 (disks and local files)**
  - With `Storage::fake('s3')`, the staged key is deleted.
  - A `Storage::extend` disk that throws `UnableToDeleteFile` ends as Failed with the message, also with
    `'throw' => true`.
  - Local: a directory ends as Failed, a missing file as Deleted, a symlink is removed but its target kept, and an
    unwritable directory ends as Failed (skipped on Windows or as root).
- **#6 (lifecycles)**
  - After `newLifecycle()`, `instanceId()` differs and the facade matches the container.
  - A real `queue:work --stop-when-empty` on the `database` queue with two jobs records two distinct ids.
  - Two `post()` requests without a reset: each file is processed once.
  - Calling `delete()` twice adds no extra rows; `delete()` with nothing staged runs 0 queries.
- **#7 (concurrency)**
  - Rows claimed by a foreign token are skipped, then reclaimed after `claim_timeout`.
  - Finishing a sweep never overwrites another token's row.
  - Integration: 5 000 rows and 4 parallel `rm-q:delete --after=0` via `Process::pool` end with every row Deleted,
    `attempts = 1` everywhere, and none Processing or Failed.
- **#8 (scale)**
  - 1 200 paths in one `stage()`, then `deleteAll()` with `chunk = 100`.
  - `DB::listen`: no statement has more than 1 000 bindings.
  - Indexes are checked with `Schema::getIndexes()`.
  - A 1 500-character path is stored (meaningful on MySQL/Postgres).
- **#9 (time)**
  - With `freezeTime()`, `travel(after - 1)` keeps the file and `+2 s` deletes it.
  - The DB jobs set the connection `timezone` to `+05:00` with the app in UTC.
- **#10 (connection)**
  - With `rm-q.connection = 'rmq'`, rows land on that connection.
  - A rolled-back `RmQ::transaction()` leaves 0 rows.
  - A test documents that staging is not atomic with a transaction on another connection.
- **#11 (test harness)**
  - `app('events')->hasListeners('eloquent.deleted: '.Picture::class)` is true.
  - The trait test is re-enabled, and the `deleteAll` test is fixed.
- **Commands**
  - Output and exit codes are asserted.
  - `--dry-run` changes nothing.
  - Cleanup retention is checked with `travel()`.
  - `rm-q:stats --json` output is checked.

**Manual end-to-end** in a throwaway Laravel 13 app (path repository):

- Publish and migrate.
- A route deletes a model inside `DB::transaction` and then throws: the file is kept.
- On the happy path, the file is gone after the response.
- A SoftDeletes model goes through the trait.
- Run `rm-q:delete --isolated`, `rm-q:stats` and `rm-q:cleanup`.
- Two jobs on `queue:work` get different `instance` values.

## 6. Out of scope (follow-ups)

- `RmQ::fake()` for consumers' tests.
- Staging the old file when a model's path column is updated.
- An optional size/mtime/ETag fingerprint to guard against path reuse.
- Renaming `RmqStageOnDeleteTrait` / `after`.

## 7. Phase notes

### Phase 1 (2026-10-12)

**Results.** Resolved stack: Laravel 13.35, Testbench 11.3, Pest 4.7.8 / PHPUnit 12.5, Larastan 3.13 /
PHPStan 2.3, symfony/uid 8.1.

| Combination (local) | Resolved | Pest | PHPStan |
|---|---|---|---|
| PHP 8.4, L13, stable | L13.35, Pest 4.7.8, PHPUnit 12.5.33, TB 11.3.0 | 14 ✓ | ✓ |
| PHP 8.4, L12, stable | L12.69.3, Pest 4.7.8, PHPUnit 12.5.33, TB 10.12.0 | 14 ✓ | ✓ |
| PHP 8.3, L12, lowest | L12.69.0, Pest 3.8.5, PHPUnit 11.5.50, TB 10.0.0 | 14 ✓ | ✓ |
| PHP 8.3, L13, lowest | L13.30.0, Pest 4.4.1, PHPUnit 12.5.12, TB 11.0.0 | 14 ✓ | ✓ |
| PHP 8.2, L12, stable | L12.69.3, Pest 3.8.7, PHPUnit 11.5.56, TB 10.12.0 | not run (local PHP 8.2 lacks `pdo_sqlite`; CI installs it) | ✓ |

`composer validate --strict`, `--strict-psr` and `pint --test` are green.

**Deviations from the plan:**

- **`RmqException` namespace fix (#1) moved into Phase 1** so the new `--strict-psr` CI step passes on this commit.
  I checked that the step fails (exit 1) with the old namespace. The named constructors stay in Phase 2.
- **PHPStan 2 / Larastan 3 reported 14 errors at level max in existing code.** They were fixed at the root, with no
  baseline and no ignores:
  - Typed the facade/`RmQ::stage()` input as `array<mixed>|string` (that is what it accepts at runtime) and narrowed
    it with `array_filter(..., 'is_string')`.
  - `Closure(Request): Response` on the middleware.
  - `Builder<static>` on the model's `@method` tags, and the `$guarded` type.
  - `rm-q:stats` reads the aggregate through `toBase()` with numeric narrowing; the stale ignores are removed.
  - The trait's abstract method is typed `string[]`, and its dead `is_string` guard is removed.
  - The test-app routes read `files` with `$request->collect()`.
- **Pint's current rules fixed `Rmq::` → `RmQ::` casing** in two tests and reordered one import.
- **`--strict-psr` runs without `--no-dev`.** `--no-dev` also drops dev packages from the autoloader, which breaks
  the Testbench `post-autoload-dump` script.
- **The `databases` CI job moved to Phase 2.** The current harness re-creates tables in every test, so it only works
  on in-memory SQLite.

**Notes:**

- `--prefer-lowest` resolves `laravel/framework` 12.69 / 13.30 (Testbench's own floors), so CI never runs the
  `^12.0` floor itself. Phase 2 only uses APIs that predate L12, so `^12.0` remains honest.
- The WIP from `main` was stashed during Phase 1 and restored on top afterwards; a backup patch is in the session
  scratchpad.

### Phase 2 (2026-10-12)

**Results:** 93 tests (87 Feature/Architecture + 6 Integration); Pint, PHPStan (level max), `composer validate
--strict` and `--strict-psr` all green.

| Combination (local) | Tests | PHPStan |
|---|---|---|
| PHP 8.4, L13.35, SQLite | 93 ✓ | ✓ |
| PHP 8.4, L13.35, **MySQL 8.0**, session tz `+05:00` (throwaway Docker container) | 93 ✓ | — |
| PHP 8.4, L12.69, stable | 93 ✓ | ✓ |
| PHP 8.3, L12.69 lowest (Pest 3 / PHPUnit 11) | 93 ✓ | ✓ |
| PHP 8.3, L13.30 lowest | 93 ✓ | ✓ |

MariaDB and Postgres run only in CI: there is no `pdo_pgsql` locally. Postgres was checked to accept the
`+05:00` session timezone; it reads it POSIX-style, as UTC-5, which is still a skewed session.

**Concurrency:** 4 parallel `rm-q:delete` processes over 5,000 rows. Each worker processed a share in every run
(e.g. 500 / 1,000 / 1,500 / 2,000), the shares always summed to exactly 5,000, and every row ended with
`attempts = 1`.

**Deviations from the specs (section 3):**

- **No constructor dependencies in `RmQ`.** The event dispatcher (`Event` facade) and disks (`Filesystem\Factory`
  contract) are resolved at call time, so `Event::fake()` and `Storage` fakes work even when they're installed
  after `RmQ` was resolved. Disks are typed as the contract: custom drivers and `Storage::set()` need not be
  `FilesystemAdapter`s.
- **`whenNoTransaction()` checks the sentinel first,** then runs now or defers with a re-check. It behaves the same
  as the sketch, but PHPStan can follow it.
- **Local deletes capture the `unlink()` warning with a scoped `set_error_handler()`.** Laravel's error handler
  handles every warning, `@`-suppressed ones included, so `error_get_last()` is always empty in a Laravel app and
  failure reasons would have been lost. Flysystem's local adapter has the same blind spot under Laravel; we call
  `getDriver()->delete()` for disks, which carries Flysystem's own message.
- **`Paths::violation()` is re-checked at sweep time** for every row, not only `allowed_directories`. Rows can be
  inserted by other means than `stage()`.
- **Dev constraint `larastan/larastan ^3.4`:** `configDirectories` first appeared in 3.4.0, and lowest-version
  installs failed on it.
- **`ProcessPoolResults` is only iterable on L13,** so the concurrency test uses `->collect()`. It also asserts the
  output regex matches, so an unexpected output can't silently count as 0.

**Behaviour changes worth knowing:**

- `stage('')`, relative paths without a disk, and non-string items now throw `RmqException` instead of being
  silently skipped or staged.
- `rm-q:cleanup` still deletes every Deleted row; retention arrives in Phase 3.
- `RmQ::transaction()` is now a plain transaction on the rm-q connection; `stage()` inserts inside it.

### Phase 3 (2026-10-12)

**Results:** 118 tests (5,388 assertions). Pint, PHPStan, `composer validate --strict` and `--strict-psr` are green.

| Combination (local) | Tests | PHPStan |
|---|---|---|
| PHP 8.4, L13.35, SQLite | 118 ✓ | ✓ |
| PHP 8.4, L13.35, MySQL 8.0, session tz `+05:00` | 118 ✓ | — |
| PHP 8.4, L12.69, stable | 118 ✓ | ✓ |
| PHP 8.3, L12.69 lowest | 118 ✓ | ✓ |
| PHP 8.3, L13.30 lowest | 118 ✓ | ✓ |

**Deviations from the specs:**

- **Unloaded columns are read, not rejected.** Spec 3.9 said to throw a `LogicException` when a path column was
  not loaded. Instead, the trait reads missing columns in `deleting`, while the row still exists. This keeps
  `Model::select('id')->get()->each->delete()` working, at the cost of one extra query. Paths are also validated in
  `deleting`, so an invalid path aborts the deletion before the record is gone. They are staged in `deleted`, once
  the DELETE has succeeded.
- **`listFilePathColumns()` is typed `array<int|string, ?string>`.** List entries must be column names;
  anything else throws a `LogicException`. Map entries may use a null disk.
- **The soft-delete check takes a `Model` parameter.** Analysed inside a SoftDeletes model, `method_exists($this,
  ...)` was "always true" for older PHPStan versions.
- **New public method `RmQ::countDeletable()`.** It shares its filters with `deleteAll()`, so `--dry-run` and
  `rm-q:stats` count exactly what a run would process.
- **`rm-q:delete` output and exit codes.** It prints a Result/Count table plus the duration, and exits `INVALID`
  (2) on bad options and `FAILURE` (1) when a file used up its attempts during the run.
- **`rm-q:cleanup` keeps rows for `cleanup_after_days` (30) by default.** Use `--days=0` for the old "remove
  everything processed" behaviour. Limited DELETEs compile on every grammar: Postgres uses `ctid`, SQLite
  `rowid`, MySQL/MariaDB `LIMIT`, SQL Server `TOP`.
- **`rm-q:stats --json` shape:** `statuses` (label ⇒ count), `total`, `oldest_staged_at` (ISO-8601 or null),
  `deletable_now` (abandoned/staged/retries) and `failed_gave_up`.
