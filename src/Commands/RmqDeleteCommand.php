<?php

namespace Medilies\RmQ\Commands;

use Illuminate\Console\Command;
use Medilies\RmQ\Facades\RmQ;

class RmqDeleteCommand extends Command
{
    public $signature = 'rm-q:delete';

    public $description = 'Delete staged files';

    // TODO: add a job

    public function handle(): int
    {
        RmQ::deleteAll();

        $this->comment('Deleted staged files');

        return self::SUCCESS;
    }
}
