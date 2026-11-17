<?php

namespace TestApp\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Medilies\RmQ\Facades\RmQ;
use Medilies\RmQ\Queue\DeleteStagedFiles;
use RuntimeException;

/** Stages files, then commits, throws, or rolls the staging back. */
class StageFilesJob implements ShouldQueue
{
    use Queueable;

    /** @param  list<string>  $paths */
    public function __construct(
        public array $paths,
        public string $outcome = 'commit',
    ) {}

    /** @return list<object> */
    public function middleware(): array
    {
        return [new DeleteStagedFiles];
    }

    public function handle(): void
    {
        if ($this->outcome === 'rollback') {
            DB::transaction(function () {
                RmQ::stage($this->paths);

                throw new RuntimeException('Rolled back after staging');
            });
        }

        RmQ::stage($this->paths);

        if ($this->outcome === 'throw') {
            throw new RuntimeException('Failed after staging');
        }
    }
}
