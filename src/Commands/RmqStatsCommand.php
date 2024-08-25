<?php

namespace Medilies\RmQ\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Medilies\RmQ\Facades\RmqException;
use Medilies\RmQ\Models\RmqFile;

class RmqStatsCommand extends Command
{
    public $signature = 'rm-q:stats';

    public $description = 'Get records count';

    public function handle(): int
    {
        /** @var array<array{Status: string, Count: int}> */
        $rows = RmqFile::select('status', DB::raw('COUNT(*) as count')) // @phpstan-ignore-line
            ->groupBy('status')
            ->get()
            ->map(fn (RmqFile $row) => [
                'Status' => $this->getStatusLabel($row->status),
                'Count' => $row->count, // @phpstan-ignore-line
            ])
            ->toArray();

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
}
