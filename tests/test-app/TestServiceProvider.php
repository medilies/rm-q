<?php

namespace TestApp;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Medilies\RmQ\Facades\RmQ;
use Medilies\RmQ\Middleware\RmqMiddleware;
use RuntimeException;

class TestServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::post('stage', function (Request $request) {
            RmQ::stage($request->collect('files')->all());
        })->middleware(RmqMiddleware::class);

        // Staged outside any transaction: nothing rolls the staging back.
        Route::post('stage-then-throw', function (Request $request) {
            RmQ::stage($request->collect('files')->all());

            throw new RuntimeException('Failed after staging');
        })->middleware('rm-q');

        // The original disaster: the outer transaction rolls back after a nested RmQ::transaction() committed.
        Route::post('stage-in-rolled-back-transaction', function (Request $request) {
            DB::transaction(function () use ($request) {
                RmQ::transaction(fn () => RmQ::stage($request->collect('files')->all()));

                throw new RuntimeException('Rolled back after staging');
            });
        })->middleware('rm-q');
    }
}
