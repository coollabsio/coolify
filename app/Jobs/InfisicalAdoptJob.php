<?php

namespace App\Jobs;

use App\Actions\Infisical\AdoptTeamSecretsIntoInfisical;
use App\Models\InfisicalConnection;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * The one upward push, queued.
 *
 * Adoption walks every project, environment and resource in the team and talks
 * to the Infisical API for each folder, so it is far too slow to run inside a
 * Livewire request. Enabling a connection dispatches this and returns; the
 * settings screen reports progress from the connection's `adopted_at`,
 * `last_synced_at`, `last_sync_status` and `last_sync_error` columns, which
 * AdoptTeamSecretsIntoInfisical writes itself.
 */
class InfisicalAdoptJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Adoption is idempotent, but a failure here is almost always a bad
     * credential or a missing machine-identity permission - neither of which a
     * retry fixes. Fail once, record why, and let the operator retry from the
     * UI.
     */
    public $tries = 1;

    public $maxExceptions = 1;

    public $timeout = 1800;

    /**
     * Named `$infisicalConnection`, not `$connection`: Laravel's `Queueable`
     * trait already defines a public `$connection` property (the queue
     * connection name), and a promoted constructor property of the same name
     * is a fatal "definition differs and is considered incompatible" error at
     * class-composition time.
     */
    public function __construct(public InfisicalConnection $infisicalConnection) {}

    public function handle(): void
    {
        try {
            AdoptTeamSecretsIntoInfisical::run($this->infisicalConnection);
        } catch (\Throwable $e) {
            // The action only stamps the connection on a run that reaches the
            // end, so a throw mid-walk would otherwise leave the screen showing
            // nothing at all.
            $this->infisicalConnection->forceFill([
                'last_synced_at' => now(),
                'last_sync_status' => InfisicalConnection::STATUS_FAILED,
                'last_sync_error' => str($e->getMessage())->limit(500)->toString(),
            ])->save();

            throw $e;
        }
    }
}
