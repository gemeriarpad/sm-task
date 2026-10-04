<?php

namespace Tests\Unit;

use App\Enums\TargetStatus;
use PHPUnit\Framework\TestCase;

class TargetStatusTest extends TestCase
{
    public function test_only_queued_and_syncing_count_as_in_progress()
    {
        $inProgress = array_filter(TargetStatus::cases(), fn (TargetStatus $status) => $status->isInProgress());

        $this->assertSame([TargetStatus::Queued, TargetStatus::Syncing], array_values($inProgress));
    }

    public function test_every_status_has_a_label()
    {
        foreach (TargetStatus::cases() as $status) {
            $this->assertNotSame('', $status->label());
        }
    }
}
