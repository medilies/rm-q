<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Medilies\RmQ\Models\RmqFile;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable(RmqFile::tableName())) {
            return;
        }

        Schema::create(RmqFile::tableName(), function (Blueprint $table) {
            $table->id();

            /* not unique */
            $table->string('path');

            $table->unsignedTinyInteger('status')->default(0)
                ->comment(RmqFile::STAGED.': staged, '.RmqFile::DELETED.': deleted, '.RmqFile::FAILED.': failed');

            // ? Failure message column
            // ? retries

            /* ULID */
            $table->string('instance', 36);

            $table->timestamp('staged_at')->useCurrent();

            $table->timestamp('processed_at')->nullable();

            $table->timestamp('deleted_at')->nullable();

            // ? index status -> instance -> staged_at
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(RmqFile::tableName());
    }
};
