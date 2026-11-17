<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Medilies\RmQ\Exceptions\RmqException;
use Medilies\RmQ\Facades\RmQ;
use Medilies\RmQ\Models\RmqFile;
use TestApp\Models\Document;
use TestApp\Models\Picture;

it('cancels the deletion of staged files', function () {
    [$kept, $deleted] = $this->temporaryFiles(2);
    RmQ::stage([$kept, $deleted]);

    expect(RmQ::unstage($kept))->toBe(1);

    RmQ::deleteAll(after: 0);

    Storage::assertExists($kept);
    Storage::assertMissing($deleted);
});

it('removes every row of the paths on that disk, failed and given up too', function () {
    config(['rm-q.max_attempts' => 3]);
    stagedRow('a.txt');
    stagedRow('a.txt', ['attempts' => 1, 'error' => 'Timeout']);
    stagedRow('a.txt', ['attempts' => 3, 'error' => 'Access Denied']);
    stagedRow('b.txt');
    stagedRow('a.txt', ['disk' => 's3']);

    expect(RmQ::unstage(['a.txt', 'missing.txt']))->toBe(3)
        ->and(RmqFile::query()->orderBy('id')->get()->map(fn (RmqFile $row) => [$row->path, $row->disk])->all())
        ->toBe([['b.txt', 'temp'], ['a.txt', 's3']]);
});

it('takes a disk', function () {
    RmQ::stage('a.txt', 's3');

    expect(RmQ::unstage('a.txt'))->toBe(0)
        ->and(RmQ::unstage('a.txt', 's3'))->toBe(1);
});

it('rolls the cancellation back with the transaction', function () {
    $file = $this->temporaryFile();
    RmQ::stage($file);

    expect(fn () => DB::transaction(function () use ($file) {
        RmQ::unstage($file);

        throw new RuntimeException('Rolled back');
    }))->toThrow(RuntimeException::class);

    expect(RmqFile::query()->count())->toBe(1);
});

it('validates paths and disks like staging does', function (mixed $paths, ?string $disk, string $message) {
    stagedRow('a.txt');

    expect(fn () => RmQ::unstage($paths, $disk))->toThrow(RmqException::class, $message)
        ->and(RmqFile::query()->count())->toBe(1);
})->with([
    'parent segment' => ['../a.txt', null, 'must not contain ".." segments'],
    'not a string' => [[123], null, 'must be a string'],
    'unknown disk' => ['a.txt', 'nope', 'disk [nope]: the disk is not configured'],
]);

it('cancels long lists in bounded statements', function () {
    $paths = array_map(fn (int $i) => "files/{$i}.txt", range(1, 2500));
    RmQ::stage($paths);

    $maxBindings = 0;
    DB::listen(function ($query) use (&$maxBindings) {
        $maxBindings = max($maxBindings, count($query->bindings));
    });

    expect(RmQ::unstage($paths))->toBe(2500)
        ->and($maxBindings)->toBeLessThan(2100)
        ->and(RmqFile::query()->count())->toBe(0);
});

it('cancels the deletion of a model restored after its deletion', function () {
    $file = $this->temporaryFile();
    $picture = Picture::query()->create(['path' => $file]);
    $picture->delete();

    // The record comes back, e.g. from a database backup.
    $restored = Picture::query()->create($picture->getAttributes());

    expect($restored->rmqUnstageFiles())->toBe(1);

    RmQ::deleteAll(after: 0);

    Storage::assertExists($file);
});

it('cancels the files of every column, on their disks', function () {
    $document = Document::query()->create(['file_path' => 'docs/report.pdf', 'thumb_path' => 'thumbs/report.png']);
    $document->delete();
    RmQ::stage('docs/other.pdf', 's3');

    $restored = Document::query()->create($document->getAttributes());

    expect($restored->rmqUnstageFiles())->toBe(2)
        ->and(RmqFile::query()->sole()->path)->toBe('docs/other.pdf');
});
