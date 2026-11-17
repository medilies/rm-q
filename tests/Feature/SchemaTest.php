<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Medilies\RmQ\Facades\RmQ;
use Medilies\RmQ\Models\RmqFile;

it('indexes the columns the sweeps filter on', function () {
    $indexes = collect(Schema::getIndexes(RmqFile::tableName()))->pluck('columns')->all();

    expect($indexes)->toContain(['status', 'staged_at'], ['instance', 'status']);
});

it('stores long paths', function () {
    Storage::fake('s3');
    $path = str_repeat('nested-directory/', 88).'file.txt'; // 1504 characters

    RmQ::stage($path, 's3');

    expect(RmqFile::query()->sole()->path)->toBe($path);
});
