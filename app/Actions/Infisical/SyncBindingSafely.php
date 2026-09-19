<?php

namespace App\Actions\Infisical;

use App\Models\InfisicalBinding;
use App\Services\Infisical\InfisicalApiException;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsAction;

class SyncBindingSafely
{
    use AsAction;

    /**
     * Sync a binding, swallowing upstream failures.
     *
     * Deploys must survive an Infisical outage by falling back to the
     * last-synced values already in Coolify's database. This is the contract
     * that justifies the sync model over resolve-at-deploy, so only
     * InfisicalApiException is caught here: a genuine bug must still surface.
     */
    public function handle(?InfisicalBinding $binding): void
    {
        if ($binding === null) {
            return;
        }

        try {
            SyncEnvironmentSecrets::run($binding);
        } catch (InfisicalApiException $e) {
            Log::warning('Infisical sync failed at deploy time; using last-synced values', [
                'binding_id' => $binding->id,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
