<?php

namespace Medilies\RmQ\Middleware;

use Closure;
use Illuminate\Http\Request;
use Medilies\RmQ\Facades\RmQ;
use Symfony\Component\HttpFoundation\Response;

class RmqMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        // ? set singleton to stage in array

        $response = $next($request);

        RmQ::delete();

        return $response;
    }
}
