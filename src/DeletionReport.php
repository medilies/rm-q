<?php

namespace Medilies\RmQ;

final class DeletionReport
{
    /** Files deleted (or already missing) in this run. */
    public int $deleted = 0;

    /** Files that could not be deleted in this run. */
    public int $failed = 0;

    /** Failures in this run that used up their last attempt; they won't be retried automatically. */
    public int $exhausted = 0;

    /** @var list<array{path: string, disk: ?string, error: string}> */
    public array $failureSample = [];

    public function __construct(
        /** Maximum number of rows to process in this run; null = unlimited. */
        public readonly ?int $limit = null,
    ) {}

    public function processed(): int
    {
        return $this->deleted + $this->failed;
    }

    public function limitReached(): bool
    {
        return $this->limit !== null && $this->processed() >= $this->limit;
    }

    /** @internal */
    public function nextChunkSize(int $chunk): int
    {
        return $this->limit === null ? $chunk : max(0, min($chunk, $this->limit - $this->processed()));
    }

    /** @internal */
    public function recordFailure(string $path, ?string $disk, string $error, bool $exhausted): void
    {
        $this->failed++;
        $this->exhausted += (int) $exhausted;

        if (count($this->failureSample) < 5) {
            $this->failureSample[] = ['path' => $path, 'disk' => $disk, 'error' => $error];
        }
    }
}
