<?php

namespace App\Actions\Sentinel;

use App\Models\FluxCertificate;
use App\Models\InstanceSettings;
use Lorisleiva\Actions\Concerns\AsAction;

class InitializeFluxTls
{
    use AsAction;

    /**
     * @param  array<int, string>  $identities
     */
    public function handle(array $identities): FluxCertificate
    {
        $certificate = (new FluxCertificate)->getConnection()->transaction(function () use ($identities): FluxCertificate {
            InstanceSettings::query()->lockForUpdate()->findOrFail(0);
            $active = FluxCertificate::query()->where('state', 'active')->get();
            if ($active->isNotEmpty()) {
                return $active->sole();
            }

            return IssueFluxCertificate::run($identities);
        }, 3);

        MaterializeFluxCertificate::run($certificate);

        return $certificate;
    }
}
