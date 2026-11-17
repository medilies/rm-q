<?php

namespace Medilies\RmQ\Events;

use Medilies\RmQ\DeletionReport;

/** Dispatched once per RmQ::delete() / RmQ::deleteAll() run. */
final class StagedFilesProcessed
{
    public function __construct(
        public readonly DeletionReport $report,
    ) {}
}
