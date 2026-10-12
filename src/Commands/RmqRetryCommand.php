<?php

namespace Medilies\RmQ\Commands;

use Illuminate\Console\Command;
use Medilies\RmQ\Enums\Status;
use Medilies\RmQ\Models\RmqFile;
use Medilies\RmQ\Support\Settings;

class RmqRetryCommand extends Command
{
    public $signature = 'rm-q:retry';

    public $description = 'Give the failed deletions that used up their attempts a new round of attempts';

    public function handle(): int
    {
        $count = RmqFile::query()
            ->where('status', Status::Failed->value)
            ->where('attempts', '>=', Settings::int('max_attempts', 1))
            ->update(['status' => Status::Staged->value, 'attempts' => 0, 'error' => null]);

        $this->components->info("{$count} failed deletion(s) will be retried by the next rm-q:delete run.");

        return self::SUCCESS;
    }
}
