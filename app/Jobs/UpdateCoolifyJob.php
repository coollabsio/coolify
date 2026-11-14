<?php

namespace App\Jobs;

use App\Actions\Server\UpdateCoolify;
use App\Exceptions\UpgradeAlreadyRunningException;
use App\Models\Server;
use App\Services\AutoUpdateNotifier;
use App\Services\CoolifyUpgradeStatus;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class UpdateCoolifyJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 600;

    public function __construct()
    {
        $this->onQueue('high');
    }

    public function handle(): void
    {
        try {
            CheckForUpdatesJob::dispatchSync();
            $settings = instanceSettings();
            if (! $settings->new_version_available) {
                Log::info('No new version available. Skipping update.');

                return;
            }

            $server = Server::findOrFail(0);
            if (! $server) {
                Log::error('Server not found. Cannot proceed with update.');

                return;
            }

            Log::info('Starting Coolify update process...');
            UpdateCoolify::run(false); // false means it's not a manual update

            Log::info('Coolify upgrade script started. Result: /data/coolify/source/.upgrade-status, log: /data/coolify/source/upgrade-*.log.');
        } catch (UpgradeAlreadyRunningException $e) {
            // The running upgrade keeps its saved attempt, so its result is still reported.
            Log::warning('Automatic update skipped: '.$e->getMessage());
        } catch (\Throwable $e) {
            Log::error('UpdateCoolifyJob failed: '.$e->getMessage());
            try {
                CoolifyUpgradeStatus::writeError(Server::findOrFail(0), $e->getMessage());
            } catch (\Throwable $statusException) {
                report($statusException);
            }
            AutoUpdateNotifier::failed($e->getMessage());
        }
    }
}
