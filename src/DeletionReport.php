<?php

namespace Medilies\RmQ;

final class DeletionReport
{
    private int $deleted = 0;

    private int $failed = 0;

    private int $exhausted = 0;

    /** @var list<array{path: string, disk: ?string, error: string}> */
    private array $failureSample = [];

    public function __construct(
        /** Maximum number of rows to process in this run; null = unlimited. */
        public readonly ?int $limit = null,
    ) {}

    /** Files deleted (or already missing) in this run. */
    public function deleted(): int
    {
        return $this->deleted;
    }

    /** Files that could not be deleted in this run. */
    public function failed(): int
    {
        return $this->failed;
    }

    /** Failures in this run that used up their last attempt; they won't be retried automatically. */
    public function exhausted(): int
    {
        return $this->exhausted;
    }

    /** @return list<array{path: string, disk: ?string, error: string}> The first 5 failures of this run. */
    public function failureSample(): array
    {
        return $this->failureSample;
    }

    public function processed(): int
    {
        return $this->deleted + $this->failed;
    }

    public function limitReached(): bool
    {
        return $this->limit !== null && $this->processed() >= $this->limit;
    }

    /** @internal Only called while the limit isn't reached. */
    public function nextChunkSize(int $chunk): int
    {
        return $this->limit === null ? $chunk : min($chunk, $this->limit - $this->processed());
    }

    /** @internal */
    public function recordDeleted(int $count): void
    {
        $this->deleted += $count;
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
