<?php

namespace Database\Seeders;

use App\Models\Server;
use Illuminate\Database\Seeder;
use App\Livewire\Server\Form;
use Illuminate\Support\Facades\DB;

class ServerTimezoneSeeder extends Seeder
{
    public function run(): void
    {
        $defaultTimezone = config('app.timezone');

        Server::whereHas('settings', function ($query) {
            $query->whereNull('server_timezone')->orWhere('server_timezone', '');
        })->each(function ($server) use ($defaultTimezone) {
            DB::transaction(function () use ($server, $defaultTimezone) {
                $server->settings->server_timezone = $defaultTimezone;
                $server->settings->save();

                $formComponent = new Form();
                $formComponent->server = $server;
                $formComponent->updateServerTimezone($defaultTimezone);

                // Refresh the server settings to ensure we have the latest data
                $server->settings->refresh();

                // Double-check and set the timezone if it's still not set
                if (!$server->settings->server_timezone) {
                    $server->settings->server_timezone = $defaultTimezone;
                    $server->settings->save();
                }
            });
        });
    }
}
