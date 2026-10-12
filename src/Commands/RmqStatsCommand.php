<?php

namespace Medilies\RmQ\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;
use Medilies\RmQ\Enums\Status;
use Medilies\RmQ\Exceptions\RmqException;
use Medilies\RmQ\Facades\RmQ;
use Medilies\RmQ\Models\RmqFile;
use Medilies\RmQ\Support\Settings;

class RmqStatsCommand extends Command
{
    public $signature = 'rm-q:stats {--json : Output the statistics as JSON}';

    public $description = 'Show the state of the rm-q table';

    public function handle(): int
    {
        $statuses = [];

        foreach (RmqFile::query()->toBase()
            ->select('status')
            ->selectRaw('COUNT(*) as aggregate')
            ->groupBy('status')
            ->orderBy('status')
            ->get() as $row) {
            $status = $this->toInt($row->status);

            $statuses[Status::tryFrom($status)?->label() ?? "Unknown ({$status})"] = $this->toInt($row->aggregate);
        }

        $oldestStaged = RmqFile::query()->where('status', Status::Staged->value)->min('staged_at');
        $oldestStaged = is_string($oldestStaged) ? Date::parse($oldestStaged) : null;

        $deletable = RmQ::countDeletable();

        $gaveUp = RmqFile::query()
            ->where('status', Status::Failed->value)
            ->where('attempts', '>=', Settings::int('max_attempts', 1))
            ->count();

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'statuses' => $statuses,
                'total' => array_sum($statuses),
                'oldest_staged_at' => $oldestStaged?->toIso8601String(),
                'deletable_now' => $deletable,
                'failed_gave_up' => $gaveUp,
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $rows = [];

        foreach ($statuses as $label => $count) {
            $rows[] = [$label, $count];
        }

        $this->table(['Status', 'Count'], [...$rows, ['Total', array_sum($statuses)]]);

        $this->components->twoColumnDetail('Oldest staged file', $oldestStaged === null
            ? '-'
            : "{$oldestStaged->toDateTimeString()} ({$oldestStaged->diffForHumans()})");
        $this->components->twoColumnDetail('Deletable now', (string) array_sum($deletable));
        $this->components->twoColumnDetail('  Staged, past the grace period', (string) $deletable['staged']);
        $this->components->twoColumnDetail('  Abandoned by a crashed run', (string) $deletable['abandoned']);
        $this->components->twoColumnDetail('  Failed, to retry', (string) $deletable['retries']);
        $this->components->twoColumnDetail('Failed, gave up', (string) $gaveUp);

        return self::SUCCESS;
    }

    private function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : throw new RmqException('Expected a numeric value from the database');
    }
}
