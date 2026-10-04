<?php

namespace App\Console\Commands;

use App\Models\Target;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncTargets extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'targets:sync {name?* : Only sync the targets with these names}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Queue a repository sync for every active target (or the given ones)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $names = array_map('mb_strtolower', $this->argument('name'));
        ['queued' => $queued, 'skipped' => $skipped] = Target::queueSyncs(
            Target::query()->active()->when($names !== [], fn ($query) => $query->whereIn(DB::raw('lower(name)'), $names)),
        );

        $this->components->info("Queued {$queued} target(s), skipped {$skipped} already in progress.");

        return self::SUCCESS;
    }
}
