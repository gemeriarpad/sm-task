<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('targets', function (Blueprint $table) {
            $table->id();
            // GitHub login as entered, uniqueness is enforced case-insensitively below.
            $table->string('name', 39);
            $table->string('type');
            // Which of the target's own public repositories are synced: "sources"
            // (no forks) or "all" (including forks).
            $table->string('repository_scope')->default('sources');
            // Clone every repository of the target (repositories can override it).
            $table->boolean('clone_repositories')->default(false);
            $table->unsignedBigInteger('github_id')->nullable()->unique();
            $table->string('avatar_url')->nullable();
            // Adding a target always queues its first sync.
            $table->string('status')->default('queued')->index();
            // Set while the target is deactivated (status "inactive").
            $table->timestamp('deactivated_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            // How many repositories GitHub returned (within the scope) in the last
            // successful sync; null before the first one.
            $table->unsignedInteger('github_repositories_count')->nullable();
            $table->timestamp('last_failed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        // GitHub logins are case-insensitive, so "Laravel" and "laravel" are the same target.
        DB::statement('CREATE UNIQUE INDEX targets_name_lower_unique ON targets ((lower(name)))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('targets');
    }
};
