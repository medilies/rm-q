<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Medilies\RmQ\Models\RmqFile;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = (new RmqFile)->getTable();

        if (Schema::hasTable($tableName)) {
            return;
        }

        Schema::create($tableName, function (Blueprint $table) {
            $table->id();

            $table->string('path');

            $table->unsignedTinyInteger('status')->default(0)
                ->comment(RmqFile::STAGED.': staged, '.RmqFile::DELETED.': deleted, '.RmqFile::FAILED.': failed');

            $table->timestamp('staged_at')->useCurrent();

            /* ULID */
            $table->string('instance', 36);

            $table->timestamp('processed_at')->nullable();

            $table->timestamp('deleted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists((new RmqFile)->getTable());
    }
};
