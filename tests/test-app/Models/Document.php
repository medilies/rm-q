<?php

namespace TestApp\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Medilies\RmQ\Concerns\StagesFilesOnDelete;

/** Files on disks: `file_path` on s3, `thumb_path` on public. */
class Document extends Model
{
    use StagesFilesOnDelete;

    protected $guarded = [];

    /** @return array<string, ?string> */
    public function rmqFileColumns(): array
    {
        return ['file_path' => 's3', 'thumb_path' => 'public'];
    }

    /**
     * An accessor that must not change what gets staged.
     *
     * @return Attribute<non-falsy-string|null, never>
     */
    protected function filePath(): Attribute
    {
        return Attribute::get(fn (mixed $value) => is_string($value) ? "https://cdn.example.com/{$value}" : null);
    }
}
