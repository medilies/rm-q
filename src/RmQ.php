<?php

namespace Medilies\RmQ;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Date;
use Medilies\RmQ\Models\RmqFile;
use Symfony\Component\Uid\Ulid;
use TypeError;

class RmQ
{
    private string $instance;

    private bool $useArray = false;

    /** @var string[] */
    private array $store = [];

    public function __construct()
    {
        $this->instance = (new Ulid)->toRfc4122();
    }

    public function useArray(): static
    {
        $this->useArray = true;

        return $this;
    }

    /** @param  string[]|string  $paths */
    public function stage(array|string $paths): void
    {
        // TODO: take query builder with one selected column => force stageInDb
        // ? validate not empty or exists?

        $this->useArray ?
            $this->stageInArray($paths) :
            $this->stageInDb($paths);
    }

    /** @param  string[]|string  $paths */
    private function stageInArray(array|string $paths): void
    {
        if (is_string($paths)) {
            $this->store[] = $paths;

            return;
        }

        /** @var string[] */
        $newPaths = collect($paths)
            ->filter(fn (mixed $path) => is_string($path))
            ->toArray();

        $this->store = array_merge($this->store, $newPaths);
    }

    /** @param  string[]|string  $paths */
    private function stageInDb(array|string $paths): void
    {
        $data = match (true) {
            is_string($paths) => $this->pathToRecord($paths),
            is_array($paths) => collect($paths)
                ->filter(fn (mixed $path) => is_string($path))
                ->map(fn (string $path) => $this->pathToRecord($path))
                ->toArray(),
        };

        RmqFile::insert($data);
    }

    public function delete(): void
    {
        $this->useArray ?
            $this->performDeleteUsingArray() :
            $this->performDeleteUsingDb(true);
    }

    public function deleteAll(): void
    {
        $after = Config::get('rm-q.after', 0);

        if (! is_int($after)) {
            throw new TypeError('rm-q.after must be an integer');
        }

        $this->performDeleteUsingDb(false, $after);
    }

    /** @return array{path: string, instance: string} */
    private function pathToRecord(string $path, int $status = RmqFile::STAGED): array
    {
        return [
            'path' => $path,
            'instance' => $this->instance,
            'status' => $status,
        ];
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
    }

    private function performDeleteUsingArray(): void
    {
        $now = Date::now();

        $data = [];

        foreach ($this->store as $path) {
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

    /** @return string[] */
    public function getStore(): array
    {
        return $this->store;
    }
}
