<?php

namespace Medilies\RmQ\Commands;

use Illuminate\Console\Command;
use Medilies\RmQ\Exceptions\RmqException;
use Medilies\RmQ\Models\RmqFile;

class RmqStatsCommand extends Command
{
    public $signature = 'rm-q:stats';

    public $description = 'Get records count';

    public function handle(): int
    {
        $counts = RmqFile::query()->toBase()
            ->select('status')
            ->selectRaw('COUNT(*) as aggregate')
            ->groupBy('status')
            ->get();

        $rows = [];

        foreach ($counts as $count) {
            $rows[] = [
                'Status' => $this->getStatusLabel($this->toInt($count->status)),
                'Count' => $this->toInt($count->aggregate),
            ];
        }

        $this->table(['Status', 'Count'], [
            ...$rows,
            [
                'Status' => 'Total',
                'Count' => array_sum(array_column($rows, 'Count')),
            ],
        ]);

        return self::SUCCESS;
    }

    private function getStatusLabel(int $status): string
    {
        return match ($status) {
            RmqFile::STAGED => 'Staged',
            RmqFile::DELETED => 'Deleted',
            RmqFile::FAILED => 'Failed',
            default => throw new RmqException("Unhandled status '{$status}'"),
        };
    }

    private function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : throw new RmqException('Expected a numeric value from the database');
    }
}
