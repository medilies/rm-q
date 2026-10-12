<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Medilies\RmQ\Facades\RmQ;

// The "solution" example of the README, as written.

beforeEach(function () {
    Storage::fake('public');

    $this->images = collect(['images/1.jpg', 'images/2.jpg'])->map(function (string $path) {
        Storage::disk('public')->put($path, 'image');

        return (new class extends Model
        {
            protected $table = 'pictures';

            protected $guarded = [];
        })->newQuery()->create(['path' => $path]);
    });
});

it('deletes the files once the transaction commits', function () {
    $images = $this->images;

    DB::transaction(function () use ($images) {
        foreach ($images as $image) {
            $image->delete();
            RmQ::stage($image->path, 'public');
        }
    });

    RmQ::delete();

    Storage::disk('public')->assertMissing(['images/1.jpg', 'images/2.jpg']);
});

it('keeps every file when the transaction fails midway', function () {
    $images = $this->images;

    expect(fn () => DB::transaction(function () use ($images) {
        foreach ($images as $i => $image) {
            $image->delete();
            RmQ::stage($image->path, 'public');

            if ($i === 1) {
                throw new RuntimeException('Failed while handling the second image');
            }
        }
    }))->toThrow(RuntimeException::class);

    RmQ::delete();

    Storage::disk('public')->assertExists(['images/1.jpg', 'images/2.jpg']);

    expect(DB::table('pictures')->count())->toBe(2);
});
