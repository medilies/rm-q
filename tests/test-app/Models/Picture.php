<?php

namespace TestApp\Models;

use Illuminate\Database\Eloquent\Model;
use Medilies\RmQ\Models\RmqStageOnDeleteTrait;

class Picture extends Model
{
    use RmqStageOnDeleteTrait;

    protected $guarded = [];

    public function listFilePathColumns(): array
    {
        return ['path'];
    }
}
