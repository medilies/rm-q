<?php

namespace Medilies\RmQ\Middleware;

use Closure;
use Illuminate\Http\Request;
use Medilies\RmQ\RmQ;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/** Deletes the files staged during the request, after the response is sent. Alias: `rm-q`. */
final class RmqMiddleware
{
    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    /** Also runs when the controller threw: only committed staging rows are swept. */
    public function terminate(Request $request, Response $response): void
    {
        try {
            app(RmQ::class)->delete();
        } catch (Throwable $e) {
            report($e); // The HTTP kernel doesn't guard terminate().
        }
    }
}
