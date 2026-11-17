<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Medilies\RmQ\Support\Settings;

return new class extends Migration
{
    /** The table must live on the connection your business transactions use. */
    public function getConnection(): ?string
    {
        return Settings::connection();
    }

    public function up(): void
    {
        Schema::create(Settings::table(), function (Blueprint $table) {
            $table->id();

            /* Absolute local path, or path on `disk`. Not unique. */
            $table->text('path');

            /* Null: `path` is an absolute local path. */
            $table->string('disk', 64)->nullable();

            /* Medilies\RmQ\Enums\Status */
            $table->unsignedTinyInteger('status')->default(0)
                ->comment('0: staged, 1: deleted, 2: failed, 3: processing');

            /* ULID of the request or job that staged the file. */
            $table->ulid('instance');

            /* Written by PHP: comparisons never depend on the database timezone. */
            $table->dateTime('staged_at');

            $table->unsignedSmallInteger('attempts')->default(0);

            /* ULID of the sweep processing the row. */
            $table->ulid('claimed_by')->nullable();

            $table->dateTime('claimed_at')->nullable();

            $table->dateTime('processed_at')->nullable();

            $table->text('error')->nullable();

            $table->index(['status', 'staged_at']);
            $table->index(['instance', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(Settings::table());
    }
};
