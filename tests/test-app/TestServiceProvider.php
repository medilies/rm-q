<?php

namespace TestApp;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Medilies\RmQ\Middleware\RmqMiddleware;
use Medilies\RmQ\RmQ;

class TestServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::post('test-middleware', function (Request $request, RmQ $rmQ) {
            /** @var array */
            $files = $request->validate([
                'files' => 'array',
                'files.*' => 'string',
            ])['files'];

            $rmQ->stage($files);
        })->middleware(RmqMiddleware::class);
    }
}
