<?php

namespace Medilies\RmQ\Commands;

use Closure;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Date;
use InvalidArgumentException;
use Medilies\RmQ\Commands\Concerns\ReadsIntegerOptions;
use Medilies\RmQ\Enums\Status;
use Medilies\RmQ\Models\RmqFile;
use Medilies\RmQ\Support\Settings;

class RmqCleanupCommand extends Command
{
    use ReadsIntegerOptions;

    /** Rows removed per statement, to keep locks and transactions short. */
    private const CHUNK = 1000;

    public $signature = 'rm-q:cleanup
        {--days= : Keep the rows processed in the last N days (default: the rm-q.cleanup_after_days config)}
        {--with-failed : Also remove the failed rows that used up their attempts}';

    public $description = 'Remove the rows of deleted files, once they are old enough, from the rm-q table';

    public function handle(): int
    {
        try {
            $days = $this->integerOption('days', 0) ?? Settings::int('cleanup_after_days');
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::INVALID;
        }

        $cutoff = Date::now()->subDays($days);

        $removed = $this->removeInChunks(fn () => RmqFile::query()
            ->where('status', Status::Deleted->value)
            ->where('processed_at', '<=', $cutoff));

        if ($this->option('with-failed')) {
            $removed += $this->removeInChunks(fn () => RmqFile::query()
                ->where('status', Status::Failed->value)
                ->where('attempts', '>=', Settings::int('max_attempts', 1))
                ->where('processed_at', '<=', $cutoff));
        }

        $this->components->info("Removed {$removed} row(s).");

        return self::SUCCESS;
    }

    /** @param  Closure(): Builder<RmqFile>  $query */
    private function removeInChunks(Closure $query): int
    {
        $removed = 0;

        do {
            $count = $query()->limit(self::CHUNK)->toBase()->delete();
            $removed += $count;
        } while ($count === self::CHUNK);

        return $removed;
    }
}
