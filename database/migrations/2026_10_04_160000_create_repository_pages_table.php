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
        // What the last complete sync got for each GitHub page of a target's repository
        // list, so the next sync can send conditional requests (If-None-Match). An
        // unchanged page comes back as 304 without a body: the stored ids say which
        // repositories it holds.
        Schema::create('repository_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('target_id')->constrained()->cascadeOnDelete();
            // The scope the page was fetched with: the users endpoint returns the same
            // URL for both scopes and forks are filtered locally.
            $table->string('scope');
            $table->string('url', 512);
            // Null for a page that must always be refetched (see RepositorySynchronizer).
            $table->string('etag')->nullable();
            $table->string('next_url', 512)->nullable();
            // GitHub ids of the in-scope repositories on the page.
            $table->json('github_ids');
            $table->timestamps();

            $table->unique(['target_id', 'url']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('repository_pages');
    }
};
