<?php

namespace Medilies\RmQ\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Medilies\RmQ\RmQ;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class RmqMiddleware
{
    public function __construct(private RmQ $rmq) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->rmq->usingMiddleware();

        $response = $next($request);

        try {
            $this->rmq->delete();
        } catch (Throwable $th) {
            Log::error('Failed while deleting the following files ['.implode(', ', $this->rmq->getStorage()).'] with error: '.$th->getMessage());
        }

        return $response;
    }
}
