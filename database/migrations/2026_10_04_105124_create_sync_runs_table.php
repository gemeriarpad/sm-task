<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // One row per job attempt, so retries show up individually in the history.
        Schema::create('sync_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('target_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('attempt');
            $table->string('status');
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedInteger('repositories_count')->nullable();
            // Cloning is part of the sync: clones made or updated, failed, and left for
            // the next sync when the run ran out of time.
            $table->unsignedInteger('clones_count')->nullable();
            $table->unsignedInteger('clone_errors_count')->nullable();
            $table->unsignedInteger('clones_deferred_count')->nullable();
            // User-facing message; the full exception goes to the log.
            $table->text('error')->nullable();
            // Whether the queue retries after this failed attempt.
            $table->boolean('will_retry')->default(false);
            $table->timestamps();

            // Dashboard: latest runs overall, and latest failed runs.
            $table->index('started_at');
            $table->index(['status', 'started_at']);
            // Cleaning up runs a timed-out job left "running" for a target.
            $table->index(['target_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sync_runs');
    }
};
