<?php

namespace Medilies\RmQ\Commands;

use Illuminate\Console\Command;
use Medilies\RmQ\Models\RmqFile;

class RmqCleanupCommand extends Command
{
    public $signature = 'rm-q:cleanup';

    public $description = 'Cleanup the table from records that were handled successfully';

    // ? add --with-failed option
    // ? --before

    public function handle(): int
    {
        RmqFile::whereDeleted()->delete();

        $this->comment('Table cleaned up.');

        return self::SUCCESS;
    }
}
