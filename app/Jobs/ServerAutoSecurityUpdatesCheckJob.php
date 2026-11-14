<?php

namespace App\Jobs;

use App\Actions\Server\CheckAutomaticSecurityUpdatesRun;
use App\Actions\Server\ConfigureAutomaticSecurityUpdates;
use App\Models\Server;
use App\Notifications\Server\AutomaticSecurityUpdatesFinished;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Saves the last finished run of the automatic security updates and notifies the team about a new run that
 * installed packages or failed.
 *
 * Without a saved run (the feature was just turned on, or the server existed before this check), the job saves
 * the current run, or an empty run when there is none, and sends nothing. Then only later runs notify.
 */
class ServerAutoSecurityUpdatesCheckJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 120;

    public $tries = 1;

    public function __construct(public Server $server)
    {
        $this->onQueue(maintenance_queue());
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('server-auto-security-updates-check-'.$this->server->uuid))->expireAfter(120)->dontRelease()];
    }

    public function handle(): void
    {
        $settings = $this->server->settings;
        $team = $this->server->team;
        $packageManager = data_get($settings->patch_check_result, 'package_manager');
        if (! $team
            || ! $settings->is_auto_security_updates_enabled
            || ! ConfigureAutomaticSecurityUpdates::supportFor($packageManager)
            || $this->server->isTransferredAway()
            || ! $this->server->isFunctional()
        ) {
            return;
        }

        try {
            $run = CheckAutomaticSecurityUpdatesRun::run($this->server, $packageManager);
        } catch (\Throwable $e) {
            Log::warning('ServerAutoSecurityUpdatesCheckJob failed: '.$e->getMessage(), ['server_id' => $this->server->id]);

            return;
        }

        $savedRun = $settings->auto_security_updates_last_run;
        if ($savedRun === null) {
            $settings->update(['auto_security_updates_last_run' => $run ?? []]);

            return;
        }
        if ($run === null || $run['started_at'] === data_get($savedRun, 'started_at')) {
            return;
        }

        $settings->update(['auto_security_updates_last_run' => $run]);
        if ($run['failed'] || $run['packages'] !== []) {
            $team->notify(new AutomaticSecurityUpdatesFinished($this->server, $run));
        }
    }
}
