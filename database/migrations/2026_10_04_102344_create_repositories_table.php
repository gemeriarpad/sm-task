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
        Schema::create('repositories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('target_id')->constrained()->cascadeOnDelete();
            // GitHub's repository id is stable across renames and transfers, so it is
            // the upsert key and the constraint that prevents duplicate rows.
            $table->unsignedBigInteger('github_id')->unique();
            $table->string('name');
            $table->string('full_name');
            $table->text('description')->nullable();
            $table->string('html_url');
            $table->string('language')->nullable();
            $table->unsignedInteger('stargazers_count')->default(0);
            $table->unsignedInteger('forks_count')->default(0);
            $table->unsignedInteger('open_issues_count')->default(0);
            // Reported by GitHub, in KB; used to skip repositories that are too large.
            $table->unsignedInteger('size_kb')->nullable();
            $table->string('default_branch')->nullable();
            $table->boolean('fork')->default(false);
            $table->boolean('archived')->default(false);
            $table->timestamp('github_updated_at')->nullable();
            $table->timestamp('pushed_at')->nullable();
            // Set to the start time of every sync that returned the repository.
            $table->timestamp('last_seen_at');
            // Set when a complete sync no longer returned the repository.
            $table->timestamp('missing_at')->nullable();
            // Excluded repositories are hidden, no longer updated by syncs and never cloned.
            $table->timestamp('excluded_at')->nullable();
            // inherit (follow the target), always or never.
            $table->string('clone_mode')->default('inherit');
            // The branch to clone; null follows the repository's default branch.
            $table->string('clone_branch')->nullable();
            // null (never cloned), queued, cloning, cloned, failed or skipped.
            $table->string('clone_status')->nullable();
            $table->string('cloned_commit', 40)->nullable();
            // The branch the local clone holds, to notice when the wanted one changes.
            $table->string('cloned_branch')->nullable();
            $table->timestamp('cloned_at')->nullable();
            $table->text('clone_error')->nullable();
            $table->timestamps();

            $table->index(['target_id', 'missing_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('repositories');
    }
};
