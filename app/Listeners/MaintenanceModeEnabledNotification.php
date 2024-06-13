<?php

namespace App\Listeners;

use Illuminate\Foundation\Events\MaintenanceModeEnabled as EventsMaintenanceModeEnabled;

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
