<?php

namespace App\Events;

use App\Traits\BroadcastsToTeam;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Laravel\Horizon\Contracts\Silenced;

class ServiceChecked implements ShouldBroadcast, Silenced
{
    use BroadcastsToTeam, Dispatchable, InteractsWithSockets, SerializesModels;
}
