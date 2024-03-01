<?php

namespace App\Listeners;

use App\Events\MaintenanceModeEnabled;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Events\MaintenanceModeEnabled as EventsMaintenanceModeEnabled;
use Illuminate\Queue\InteractsWithQueue;

class MaintenanceModeEnabledNotification
{
    /**
     * Create the event listener.
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     */
    public function handle(EventsMaintenanceModeEnabled $event): void
    {
        ray('Maintenance mode enabled!');
    }
}
