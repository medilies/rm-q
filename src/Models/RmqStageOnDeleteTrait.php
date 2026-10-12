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
                $path = $model->$column ?? null;

                if (is_null($path)) {
                    continue;
                }

                $files[] = $path;
            }

            RmQ::stage($files);
        });
    }

    /** @return string[] */
    abstract public function listFilePathColumns(): array;
}
