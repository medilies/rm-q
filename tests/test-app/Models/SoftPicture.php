<?php

namespace TestApp\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Medilies\RmQ\Models\RmqStageOnDeleteTrait;

class SoftPicture extends Model
{
    use RmqStageOnDeleteTrait;
    use SoftDeletes;

    protected $guarded = [];

    /** @return array<int|string, ?string> */
    public function listFilePathColumns(): array
    {
        return ['path'];
    }
}
