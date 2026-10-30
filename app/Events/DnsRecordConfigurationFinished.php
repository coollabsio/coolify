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

    /**
     * The team channel also reaches members without access to the resource, so only Coolify can read the data.
     *
     * @return array{payload: string}
     */
    public function broadcastWith(): array
    {
        return ['payload' => encrypt([
            'teamId' => $this->teamId,
            'resourceType' => $this->resourceType,
            'resourceId' => $this->resourceId,
            'hostname' => $this->hostname,
            'successful' => $this->successful,
            'credential' => $this->credential,
            'message' => $this->message,
        ])];
    }
}
