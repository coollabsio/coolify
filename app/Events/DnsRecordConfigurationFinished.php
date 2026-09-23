<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DnsRecordConfigurationFinished implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public int $teamId,
        public ?string $resourceType,
        public int|string|null $resourceId,
        public string $hostname,
        public bool $successful,
        public string $credential,
        public string $message,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("team.{$this->teamId}")];
    }
}
