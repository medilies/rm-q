<?php

namespace Medilies\RmQ;

use Closure;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Medilies\RmQ\Models\RmqFile;
use Symfony\Component\Uid\Ulid;
use Throwable;
use TypeError;

class RmQ
{
    private string $instance;

    private bool $isUsingMiddleware = false;

    private bool $isWithinTransaction = false;

    private bool $hasFilesInDb = false;

    /** @var string[] */
    private array $arrayStorage = []; // ? Dto[]

    /** @var string[] */
    private array $transactionStorage = [];

    public function __construct()
    {
        $this->instance = (new Ulid)->toRfc4122();
    }

    public function transaction(Closure $callback): static
    {
        $this->withinTransaction();

        try {
            DB::transaction($callback); // @phpstan-ignore-line

            if ($this->isUsingMiddleware) {
                $this->arrayStorage = array_merge($this->arrayStorage, $this->transactionStorage);
            } else {
                $this->stageInDb($this->transactionStorage);
            }
        } catch (Throwable $th) {
            throw $th;
        } finally {
            $this->transactionStorage = [];

            $this->withinTransaction(false);
        }

        return $this;
    }

    /** @param  string[]|string  $paths */
    public function stage(array|string $paths): void
    {
        // TODO: take query builder with one selected column => force stageInDb
        // ? validate not empty or exists?

        $this->isWithinTransaction ?
            $this->stageInTransactionStorage($paths) :
            $this->stageInDb($paths);
    }

    /** @param  string[]|string  $paths */
    private function stageInTransactionStorage(array|string $paths): void
    {
        if (is_string($paths)) {
            $this->transactionStorage[] = $paths;

            return;
        }

        /** @var string[] */
        $newPaths = collect($paths)
            ->filter(fn (mixed $path) => is_string($path))
            ->toArray();

        $this->transactionStorage = array_merge($this->transactionStorage, $newPaths);
    }

    /** @param  string[]|string  $paths */
    private function stageInDb(array|string $paths): void
    {
        $data = match (true) {
            is_string($paths) => $this->pathToStagedRecord($paths),
            is_array($paths) => collect($paths)
                ->filter(fn (mixed $path) => is_string($path))
                ->map(fn (string $path) => $this->pathToStagedRecord($path))
                ->toArray(),
        };

        RmqFile::insert($data);

        $this->hasFilesInDb = true;
    }

    public function delete(): void
    {
        $this->performDeleteUsingDb(true);
        $this->performDeleteUsingArrayStorage();
    }

    public function deleteAll(): void
    {
        $after = Config::get('rm-q.after', 0);

        if (! is_int($after)) {
            throw new TypeError('rm-q.after must be an integer');
        }

        if ($this->hasFilesInDb) {
            $this->performDeleteUsingDb(false, $after);
        }

        $this->performDeleteUsingArrayStorage();
    }

    private function performDeleteUsingDb(bool $filterInstance = false, int $beforeSeconds = 0): void
    {
        $now = Date::now();

        $deletedIds = $failedIds = [];

        RmqFile::whereStaged()
            ->whereBeforeSeconds($beforeSeconds)
            ->whereInstance($filterInstance ? $this->instance : null)
            ->get()
            ->each(function (RmqFile $file) use (&$deletedIds, &$failedIds) {
                if (@unlink($file->path)) {
                    $deletedIds[] = $file->id;
                } else {
                    $failedIds[] = $file->id;
                }
            });

        if (count($deletedIds) > 0) {
            RmqFile::whereIn('id', $deletedIds)->update([
                'status' => RmqFile::DELETED,
                'processed_at' => $now,
                'deleted_at' => $now,
            ]);
        }

        if (count($failedIds) > 0) {
            RmqFile::whereIn('id', $failedIds)->update([
                'status' => RmqFile::FAILED,
                'processed_at' => $now,
            ]);
        }

        $this->hasFilesInDb = false;
    }

    private function performDeleteUsingArrayStorage(): void
    {
        $now = Date::now();

        $data = [];

        foreach ($this->arrayStorage as $path) {
            if (@unlink($path)) {
                $data[] = [
                    'path' => $path,
                    'instance' => $this->instance,
                    'status' => RmqFile::DELETED,
                    'processed_at' => $now,
                    'deleted_at' => $now,
                ];
            } else {
                $data[] = [
                    'path' => $path,
                    'instance' => $this->instance,
                    'status' => RmqFile::FAILED,
                    'processed_at' => $now,
                ];
            }
        }

        RmqFile::insert($data);
    }

    public function usingMiddleware(bool $flag = true): static
    {
        $this->isUsingMiddleware = $flag;

        return $this;
    }

    public function withinTransaction(bool $flag = true): static
    {
        $this->isWithinTransaction = $flag;

        return $this;
    }

    /** @return array{path: string} */
    private function pathToStagedRecord(string $path): array
    {
        return [
            'path' => $path,
            'instance' => $this->instance,
            'status' => RmqFile::STAGED,
        ];
    }

    /** @return string[] */
    public function getStorage(): array
    {
        return $this->arrayStorage;
    }
}
