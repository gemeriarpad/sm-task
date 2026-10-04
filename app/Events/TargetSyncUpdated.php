<?php

namespace App\Events;

use App\Models\Target;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A target's sync state changed. Pages listening on the private "sync" channel
 * use it as a signal to reload their data; the payload is kept small on purpose.
 *
 * Broadcast immediately (not queued) so the update isn't stuck behind sync jobs.
 */
class TargetSyncUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public readonly Target $target) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('sync')];
    }

    public function broadcastAs(): string
    {
        return 'target.sync.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'target_id' => $this->target->id,
            'status' => $this->target->status->value,
        ];
    }
}
