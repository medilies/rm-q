<?php

namespace Medilies\RmQ\Queue;

use Closure;
use Medilies\RmQ\RmQ;
use Throwable;

/**
 * Job middleware: deletes the files staged during the job once it finishes, even if it failed.
 * Only committed staging rows are swept, so work rolled back by the job keeps its files.
 */
final class DeleteStagedFiles
{
    /** @param  Closure(object): mixed  $next */
    public function handle(object $job, Closure $next): mixed
    {
        try {
            return $next($job);
        } finally {
            try {
                app(RmQ::class)->delete();
            } catch (Throwable $e) {
                report($e);
            }
        }
    }
}
