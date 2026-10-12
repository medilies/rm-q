<?php

namespace TestApp;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Medilies\RmQ\Exceptions\RmqException;
use Medilies\RmQ\Middleware\RmqMiddleware;
use Medilies\RmQ\RmQ;

class TestServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::post('test-middleware', function (Request $request, RmQ $rmQ) {
            $request->validate([
                'files' => 'array',
                'files.*' => 'string',
            ]);

            $rmQ->stage($request->collect('files')->all());
        })->middleware(RmqMiddleware::class);

        Route::post('test-middleware-exception', function (Request $request, RmQ $rmQ) {
            $request->validate([
                'files' => 'array',
                'files.*' => 'string',
            ]);

            $rmQ->stage($request->collect('files')->all());

            throw new RmqException;
        })->middleware(RmqMiddleware::class);
    }
}
