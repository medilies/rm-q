<?php

namespace Medilies\RmQ\Commands;

use Illuminate\Console\Command;
use Medilies\RmQ\Facades\RmQ;

class RmqDeleteCommand extends Command
{
    public $signature = 'rm-q:delete';

    public $description = 'Delete staged files';

    public function handle(): int
    {
        $report = RmQ::deleteAll();

        $this->comment("Deleted {$report->deleted} staged file(s), {$report->failed} failed.");

        return self::SUCCESS;
    }
}
