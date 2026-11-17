<?php

namespace Medilies\RmQ\Commands;

use Illuminate\Console\Command;
use Medilies\RmQ\Enums\Status;
use Medilies\RmQ\Models\RmqFile;

class RmqRetryCommand extends Command
{
    public $signature = 'rm-q:retry';

    public $description = 'Give the failed deletions that used up their attempts a new round of attempts';

    public function handle(): int
    {
        $count = RmqFile::query()
            ->gaveUp()
            ->update(['status' => Status::Staged->value, 'attempts' => 0, 'error' => null]);

        $this->components->info("{$count} failed deletion(s) will be retried by the next rm-q:delete run.");

        return self::SUCCESS;
    }
}
