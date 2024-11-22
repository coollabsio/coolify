<?php

namespace App\Actions\License;

use Illuminate\Support\Facades\Http;
use Lorisleiva\Actions\Concerns\AsAction;

class CheckResaleLicense
{
    use AsAction;

    public function handle()
    {
        try {
            $settings = instanceSettings();
            if (isDev()) {
                $settings->update([
                    'is_resale_license_active' => true,
                ]);

                return;
            }
            // if (!$settings->resale_license) {
            //     return;
            // }
            $base_url = config('coolify.license_url');
            $instance_id = config('app.id');
            $data = Http::withHeaders([
                'Accept' => 'application/json',
            ])->get("$base_url/lemon/validate", [
                'license_key' => $settings->resale_license,
                'instance_id' => $instance_id,
            ])->json();
            if (data_get($data, 'valid') === true && data_get($data, 'license_key.status') === 'active') {
                $settings->update([
                    'is_resale_license_active' => true,
                ]);

                return;
            }
            $data = Http::withHeaders([
                'Accept' => 'application/json',
            ])->get("$base_url/lemon/activate", [
                'license_key' => $settings->resale_license,
                'instance_id' => $instance_id,
            ])->json();
            if (data_get($data, 'activated') === true) {
                $settings->update([
                    'is_resale_license_active' => true,
                ]);

                return;
            }
            if (data_get($data, 'license_key.status') === 'active') {
                throw new \Exception('Invalid license key.');
            }
            throw new \Exception('Cannot activate license key.');
        } catch (\Throwable $e) {
            $settings->update([
                'resale_license' => null,
                'is_resale_license_active' => false,
            ]);
            throw $e;
        }
    }
}
