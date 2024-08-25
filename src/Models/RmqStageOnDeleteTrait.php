<?php

namespace Medilies\RmQ\Models;

use Medilies\RmQ\Facades\RmQ;

trait RmqStageOnDeleteTrait
{
    public static function bootRmqStageOnDeleteTrait(): void
    {
        static::deleted(function (self $model) {
            $columns = $model->listFilePathColumns();

            $files = [];
            foreach ($columns as $column) {
                if (! is_string($column)) {
                    // ? log or throw
                    continue;
                }

                $path = $model->$column ?? null;

                if (is_null($path)) {
                    continue;
                }

                $files[] = $path;
            }

            RmQ::stage($files);
        });
    }

    abstract public function listFilePathColumns(): array;
}
