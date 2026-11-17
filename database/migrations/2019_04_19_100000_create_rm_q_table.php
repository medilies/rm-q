<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;

/*
 * Reads the rm-q config, not the package's classes: published, this migration must keep working after the package
 * is removed. Changing rm-q.connection or rm-q.table after migrating needs a migration of your own.
 */
return new class extends Migration
{
    /** The table must live on the connection your business transactions use. */
    public function getConnection(): ?string
    {
        /** @var string|null $connection */
        $connection = Config::get('rm-q.connection');

        return $connection;
    }

    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table) {
            $table->id();

            /* Path on `disk`. Not unique. */
            $table->text('path');

            $table->string('disk', 64);

            /* UTC, written by PHP: comparisons depend on neither the app nor the database timezone. */
            $table->dateTime('staged_at')->index();

            /* Counted before each deletion attempt. */
            $table->unsignedSmallInteger('attempts')->default(0);

            /* UTC. Set with each attempt: not retried before then. Null until the first attempt. */
            $table->dateTime('next_attempt_at')->nullable();

            /* Why the last attempt failed. */
            $table->text('error')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    private function table(): string
    {
        return Config::string('rm-q.table', 'rm_q_files');
    }
};
