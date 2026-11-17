<?php

namespace TestApp\Models;

use Illuminate\Database\Eloquent\Model;
use Medilies\RmQ\Concerns\StagesFilesOnDelete;

class Picture extends Model
{
    use StagesFilesOnDelete;

    protected $guarded = [];

    /** @return array<string, ?string> Null: the default disk. */
    public function rmqFileColumns(): array
    {
        return ['path' => null];
    }
}
