<?php

namespace Medilies\RmQ\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Date;
use Medilies\RmQ\Facades\RmQ;
use Medilies\RmQ\Models\RmqFile;

class RmqStatsCommand extends Command
{
    public $signature = 'rm-q:stats {--json : Output the statistics as JSON}';

    public $description = 'Show the state of the rm-q table';

    public function handle(): int
    {
        $pending = RmqFile::query()->where('attempts', '<', Config::integer('rm-q.max_attempts'));

        $oldestStaged = $pending->clone()->min('staged_at');
        $oldestStaged = is_string($oldestStaged)
            ? Date::parse($oldestStaged, 'UTC')->setTimezone(date_default_timezone_get())
            : null;

        $stats = [
            'pending' => $pending->clone()->count(),
            'deletable_now' => RmQ::countDeletable(),
            'retrying' => $pending->clone()->whereNotNull('error')->count(),
            'gave_up' => RmqFile::query()->gaveUp()->count(),
            'oldest_staged_at' => $oldestStaged?->toIso8601String(),
        ];

        if ($this->option('json')) {
            $this->line(json_encode($stats, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('Pending', (string) $stats['pending']);
        $this->components->twoColumnDetail('  Deletable now', (string) $stats['deletable_now']);
        $this->components->twoColumnDetail('  Failed, will retry', (string) $stats['retrying']);
        $this->components->twoColumnDetail('Failed, gave up', (string) $stats['gave_up']);
        $this->components->twoColumnDetail('Oldest staged file', $oldestStaged === null
            ? '-'
            : "{$oldestStaged->toDateTimeString()} ({$oldestStaged->diffForHumans()})");

        return self::SUCCESS;
    }
}
