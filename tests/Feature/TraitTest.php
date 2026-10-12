<?php

use Illuminate\Support\Facades\DB;
use Medilies\RmQ\Enums\Status;
use Medilies\RmQ\Facades\RmQ;
use Medilies\RmQ\Models\RmqFile;
use TestApp\Models\Picture;

it('listens to the deleted event of the model', function () {
    new Picture; // boots the model

    expect(app('events')->hasListeners('eloquent.deleted: '.Picture::class))->toBeTrue();
});

it('stages the files of a deleted model', function () {
    $file = $this->temporaryFile();
    $picture = Picture::query()->create(['path' => $file]);

    $picture->delete();

    expect(RmqFile::query()->sole())
        ->path->toBe($file)
        ->status->toBe(Status::Staged);

    RmQ::delete();

    expect($file)->not->toBeFile();
});

it('skips empty path columns', function () {
    Picture::query()->create(['path' => null])->delete();

    expect(RmqFile::query()->count())->toBe(0);
});

it('stages nothing when the deletion rolls back', function () {
    $file = $this->temporaryFile();
    $picture = Picture::query()->create(['path' => $file]);

    expect(fn () => DB::transaction(function () use ($picture) {
        $picture->delete();

        throw new RuntimeException('Rolled back');
    }))->toThrow(RuntimeException::class);

    expect(RmqFile::query()->count())->toBe(0)
        ->and(Picture::query()->count())->toBe(1);
});
