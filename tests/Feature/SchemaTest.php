<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Medilies\RmQ\Facades\RmQ;
use Medilies\RmQ\Models\RmqFile;

it('indexes the grace period column', function () {
    $indexes = collect(Schema::getIndexes((new RmqFile)->getTable()))->pluck('columns')->all();

    expect($indexes)->toContain(['staged_at']);
});

it('stores long paths', function () {
    Storage::fake('s3');
    $path = str_repeat('nested-directory/', 88).'file.txt'; // 1504 characters

    RmQ::stage($path, 's3');

    expect(RmqFile::query()->sole()->path)->toBe($path);
});
