<?php

namespace Medilies\RmQ\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Isolatable;
use InvalidArgumentException;
use Medilies\RmQ\Facades\RmQ;
use Medilies\RmQ\Support\GracePeriod;

class RmqDeleteCommand extends Command implements Isolatable
{
    public $signature = 'rm-q:delete
        {--after= : Grace period in seconds before a staged file is deleted (default: the rm-q.after config)}
        {--limit= : Maximum number of files to process}
        {--dry-run : Count the files that would be processed, without deleting anything}';

    public $description = 'Delete the staged files whose grace period is over, and retry failed deletions';

    public function handle(): int
    {
        try {
            $after = $this->integerOption('after', 0);
            $limit = $this->integerOption('limit', 1);
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::INVALID;
        }

        if ($after === null) {
            $gracePeriod = GracePeriod::fromConfig();
            $after = $gracePeriod->seconds;

            if ($gracePeriod->warning !== null) {
                $this->components->warn($gracePeriod->warning);
            }
        }

        if ($this->option('dry-run')) {
            $this->components->info('Would process '.RmQ::countDeletable($after).' staged file(s).');

            return self::SUCCESS;
        }

        $started = microtime(true);
        $report = RmQ::deleteAll($after, $limit);

        $this->table(['Result', 'Count'], [
            ['Deleted', $report->deleted],
            ['Failed, will retry', $report->failed - $report->exhausted],
            ['Failed, gave up', $report->exhausted],
        ]);

        $this->components->info(sprintf('Processed %d staged file(s) in %.2fs.', $report->processed(), microtime(true) - $started));

        if ($report->exhausted > 0) {
            $this->components->error("{$report->exhausted} file(s) could not be deleted within the maximum attempts. Run rm-q:retry once the cause is fixed.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /** @throws InvalidArgumentException */
    private function integerOption(string $name, int $min): ?int
    {
        $value = $this->option($name);

        if ($value === null) {
            return null;
        }

        if (! is_string($value) || ! ctype_digit($value) || (int) $value < $min) {
            throw new InvalidArgumentException("The --{$name} option must be an integer greater than or equal to {$min}.");
        }

        return (int) $value;
    }
}
