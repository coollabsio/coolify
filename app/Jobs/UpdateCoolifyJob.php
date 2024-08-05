<?php

namespace App\Jobs;

use App\Actions\Server\UpdateCoolify;
use App\Models\InstanceSettings;
use App\Models\Server;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class UpdateCoolifyJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 3600; // 1 hour timeout

    public function handle(): void
    {
        try {
            $settings = InstanceSettings::get();
            if (!$settings->is_auto_update_enabled || !$settings->new_version_available) {
                return;
            }

            $server = Server::findOrFail(0);
            if (!$server) {
                return;
            }

            UpdateCoolify::run(false); // false means it's not a manual update

            // After successful update, reset the new_version_available flag
            $settings->update(['new_version_available' => false]);

        } catch (\Throwable $e) {
            // Log the error or send a notification
            ray('UpdateCoolifyJob failed: ' . $e->getMessage());
        }
    }
}