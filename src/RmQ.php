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

    public function __construct()
    {
        $this->instance = (new Ulid)->toRfc4122();
    }

    /** @param  string[]|string  $paths */
    public function stage(array|string $paths): void
    {
        // TODO: take query builder with one selected column
        // ? validate not empty or exists?
        // ? Stage in array and persist by the end of the process. The middleware can parametrize the singleton

        $data = match (true) {
            is_string($paths) => $this->pathToRecord($paths),
            is_array($paths) => collect($paths)
                ->map(fn (string $path) => $this->pathToRecord($path))
                ->toArray(),
        };

        RmqFile::insert($data);
    }

    public function delete(): void
    {
        $this->performDelete(true);
    }

    public function deleteAll(): void
    {
        $after = Config::get('rm-q.after', 0);

        if (! is_int($after)) {
            throw new TypeError('rm-q.after must be an integer');
        }

        $this->performDelete(false, $after);
    }

    /** @return array{path: string, instance: string} */
    private function pathToRecord(string $path): array
    {
        return [
            'path' => $path,
            'instance' => $this->instance,
        ];
    }

    private function performDelete(bool $filterInstance = false, int $beforeSeconds = 0): void
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
}
