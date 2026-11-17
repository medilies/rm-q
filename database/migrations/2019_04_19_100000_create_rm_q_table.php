<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Medilies\RmQ\Models\RmqFile;

return new class extends Migration
{
    /** The table must live on the connection your business transactions use. */
    public function getConnection(): ?string
    {
        return (new RmqFile)->getConnectionName();
    }

    public function up(): void
    {
        Schema::create((new RmqFile)->getTable(), function (Blueprint $table) {
            $table->id();

            /* Path on `disk`. Not unique. */
            $table->text('path');

            $table->string('disk', 64);

            /* UTC, written by PHP: comparisons depend on neither the app nor the database timezone. */
            $table->dateTime('staged_at')->index();

            /* Counted before each deletion attempt. */
            $table->unsignedSmallInteger('attempts')->default(0);

            /* Why the last attempt failed. */
            $table->text('error')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists((new RmqFile)->getTable());
    }
};
