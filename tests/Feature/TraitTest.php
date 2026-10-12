<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Medilies\RmQ\Enums\Status;
use Medilies\RmQ\Exceptions\RmqException;
use Medilies\RmQ\Facades\RmQ;
use Medilies\RmQ\Models\RmqFile;
use Medilies\RmQ\Models\RmqStageOnDeleteTrait;
use TestApp\Models\Document;
use TestApp\Models\Picture;
use TestApp\Models\SoftPicture;

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
        ->disk->toBeNull()
        ->status->toBe(Status::Staged);

    RmQ::delete();

    expect($file)->not->toBeFile();
});

it('skips empty path columns', function (?string $path) {
    Picture::query()->create(['path' => $path])->delete();

    expect(RmqFile::query()->count())->toBe(0);
})->with(['null' => null, 'empty' => '']);

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

it('keeps the files of soft deleted models', function () {
    $file = $this->temporaryFile();
    $picture = SoftPicture::query()->create(['path' => $file]);

    $picture->delete();

    expect(RmqFile::query()->count())->toBe(0)
        ->and($picture->restore())->toBeTrue()
        ->and(SoftPicture::query()->sole()->path)->toBe($file);
});

it('stages the files of force deleted models', function (bool $trashedFirst) {
    $file = $this->temporaryFile();
    $picture = SoftPicture::query()->create(['path' => $file]);

    if ($trashedFirst) {
        $picture->delete();
    }

    $picture->forceDelete();

    expect(RmqFile::query()->sole()->path)->toBe($file)
        ->and(SoftPicture::withTrashed()->count())->toBe(0);
})->with(['live' => false, 'trashed' => true]);

it('does not see deletions that skip model events', function () {
    Picture::query()->create(['path' => $this->temporaryFile()])->deleteQuietly();
    SoftPicture::query()->create(['path' => $this->temporaryFile()])->forceDeleteQuietly();

    expect(RmqFile::query()->count())->toBe(0)
        ->and(SoftPicture::withTrashed()->count())->toBe(0);
});

it('stages raw column values on their disks', function () {
    $document = Document::query()->create(['file_path' => 'docs/report.pdf', 'thumb_path' => 'thumbs/report.png']);

    expect($document->file_path)->toBe('https://cdn.example.com/docs/report.pdf'); // the accessor

    $document->delete();

    expect(RmqFile::query()->get()->map(fn (RmqFile $row) => [$row->path, $row->disk])->all())
        ->toEqualCanonicalizing([['docs/report.pdf', 's3'], ['thumbs/report.png', 'public']]);
});

it('reads the path columns a partial select left out', function () {
    $file = $this->temporaryFile();
    Picture::query()->create(['path' => $file]);

    Picture::query()->select('id')->sole()->delete();

    expect(RmqFile::query()->sole()->path)->toBe($file);
});

it('refuses to delete a model whose path is invalid', function () {
    $picture = Picture::query()->create(['path' => 'relative/file.txt']);

    expect(fn () => $picture->delete())->toThrow(RmqException::class, 'must be absolute');

    expect(Picture::query()->count())->toBe(1)
        ->and(RmqFile::query()->count())->toBe(0);
});

it('rejects a malformed column list', function () {
    $model = new class extends Model
    {
        use RmqStageOnDeleteTrait;

        protected $table = 'pictures';

        protected $guarded = [];

        public function listFilePathColumns(): array
        {
            return [null];
        }
    };

    $model->forceFill(['path' => '/tmp/file.txt'])->save();

    expect(fn () => $model->delete())->toThrow(LogicException::class, 'listFilePathColumns() must list column names');
});
