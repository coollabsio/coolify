<?php

namespace App\Events;

use App\Models\Server;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ProxyStarted
{
    use Dispatchable, InteractsWithSockets, SerializesModels;
    public function __construct(public Server $server)
    {
    }
}
