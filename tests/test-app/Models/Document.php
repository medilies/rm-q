<?php

namespace TestApp\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Medilies\RmQ\Models\RmqStageOnDeleteTrait;

/** Files on disks: `file_path` on s3, `thumb_path` on the model's default disk. */
class Document extends Model
{
    use RmqStageOnDeleteTrait;

    protected $guarded = [];

    /** @return array<int|string, ?string> */
    public function listFilePathColumns(): array
    {
        return ['file_path' => 's3', 'thumb_path'];
    }

    public function rmqDisk(): ?string
    {
        return 'public';
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
