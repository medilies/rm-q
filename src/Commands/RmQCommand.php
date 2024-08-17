<?php

namespace Medilies\RmQ\Commands;

use Illuminate\Console\Command;

class RmQCommand extends Command
{
    public $signature = 'rm-q';

    public $description = 'My command';

    public function handle(): int
    {
        $this->comment('All done');

        return self::SUCCESS;
    }
}
