<?php

namespace App\Events;

use App\Models\Server;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ProxyStatusChanged
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array{server_id: int, team_id: int}|mixed  $data  Build it with dataFor(); the listener ignores any other shape.
     */
    public function __construct(public $data) {}

    /**
     * @return array{server_id: int, team_id: int}
     */
    public static function dataFor(Server $server): array
    {
        return ['server_id' => $server->id, 'team_id' => $server->team_id];
    }
}
