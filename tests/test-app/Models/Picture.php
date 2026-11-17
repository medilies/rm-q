<?php

namespace TestApp\Models;

use Illuminate\Database\Eloquent\Model;
use Medilies\RmQ\Concerns\StagesFilesOnDelete;

class Picture extends Model
{
    use StagesFilesOnDelete;

    protected $guarded = [];

    /** @return array<string, ?string> */
    public function rmqFileColumns(): array
    {
        return ['path' => null];
    }
}
