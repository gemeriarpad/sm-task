<?php

namespace App\Http\Controllers;

use App\Concerns\DataTableQuery;
use App\Models\Target;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The queue's failed jobs, with the `queue:retry` and `queue:forget` commands as buttons.
 */
class FailedJobController extends Controller
{
    use DataTableQuery;

    public function __construct(private readonly FailedJobProviderInterface $failer) {}

    public function index(Request $request): Response
    {
        $jobs = DB::connection(config('queue.failed.database'))
            ->table(config('queue.failed.table'))
            ->orderByDesc('failed_at')
            ->orderByDesc('id')
            ->paginate($this->perPage($request, default: 25))
            ->withQueryString();

        $items = $jobs->getCollection()->map(fn (object $job) => $this->item($job));
        $targets = Target::query()->whereKey($items->pluck('target_id')->filter()->unique())->pluck('name', 'id');

        $jobs->setCollection($items->map(fn (array $item) => [
            ...$item,
            'target' => $item['target_id'] && $targets->has($item['target_id'])
                ? ['id' => $item['target_id'], 'name' => $targets[$item['target_id']]]
                : null,
        ]));

        return Inertia::render('failed-jobs/Index', ['jobs' => $jobs]);
    }

    /**
     * Push the job back onto its queue (`php artisan queue:retry <uuid>`).
     */
    public function retry(string $uuid): RedirectResponse
    {
        abort_if($this->failer->find($uuid) === null, 404);

        Artisan::call('queue:retry', ['id' => [$uuid]]);

        return back();
    }

    /**
     * Delete the failed job without running it again (`php artisan queue:forget <uuid>`).
     */
    public function destroy(string $uuid): RedirectResponse
    {
        abort_if($this->failer->find($uuid) === null, 404);

        $this->failer->forget($uuid);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function item(object $job): array
    {
        $payload = json_decode($job->payload, true) ?: [];

        return [
            'id' => $job->id,
            'uuid' => $job->uuid,
            'connection' => $job->connection,
            'queue' => $job->queue,
            'job' => class_basename($payload['displayName'] ?? 'Unknown job'),
            'target_id' => $this->targetId($payload),
            'attempts' => $payload['attempts'] ?? null,
            // Only the message written for users; the raw exception stays in the table
            // and the logs. Jobs that failed before messages were stored get a generic one.
            'message' => $job->message ?? 'The job failed. The error has been logged.',
            'failed_at' => Carbon::parse($job->failed_at)->toIso8601String(),
        ];
    }

    /**
     * The target a sync job was for. Read from the serialized model identifier with a
     * pattern instead of unserializing the payload, which this page doesn't need to do.
     *
     * @param  array<string, mixed>  $payload
     */
    private function targetId(array $payload): ?int
    {
        $command = $payload['data']['command'] ?? '';

        return is_string($command) && preg_match('/s:5:"class";s:\d+:"App\\\\Models\\\\Target";s:2:"id";i:(\d+);/', $command, $matches)
            ? (int) $matches[1]
            : null;
    }
}
