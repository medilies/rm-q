<?php

namespace TestApp\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Medilies\RmQ\Concerns\StagesFilesOnDelete;

class SoftPicture extends Model
{
    use SoftDeletes;
    use StagesFilesOnDelete;

    protected $guarded = [];

    /** @return array<string, ?string> Null: the default disk. */
    public function rmqFileColumns(): array
    {
        return ['path' => null];
    }
}
