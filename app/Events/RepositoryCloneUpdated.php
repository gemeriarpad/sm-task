<?php

namespace App\Events;

use App\Models\Repository;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A repository's clone state changed; the repository page reloads on it, and so
 * do the pages showing a target's clone counts.
 */
class RepositoryCloneUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public readonly Repository $repository) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('sync')];
    }

    public function broadcastAs(): string
    {
        return 'repository.clone.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'repository_id' => $this->repository->id,
            'target_id' => $this->repository->target_id,
            'clone_status' => $this->repository->clone_status?->value,
        ];
    }
}
