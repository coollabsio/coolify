<?php

namespace App\Actions\Service;

use App\Models\Environment;
use App\Models\Service;

class RegenerateEnvironmentServices
{
    /**
     * @return array{services:int,restored:int,parsed:int,failed:int}
     */
    public function handle(Environment $environment): array
    {
        $services = Service::withTrashed()
            ->where('environment_id', $environment->id)
            ->get();

        $restored = 0;
        $parsed = 0;
        $failed = 0;

        foreach ($services as $service) {
            try {
                if ($service->trashed()) {
                    $service->restore();
                    $restored++;
                }

                $service->parse();
                $parsed++;
            } catch (\Throwable) {
                $failed++;
            }
        }

        return [
            'services' => $services->count(),
            'restored' => $restored,
            'parsed' => $parsed,
            'failed' => $failed,
        ];
    }
}
