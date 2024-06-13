<?php

namespace App\Actions\License;

use App\Models\InstanceSettings;
use Illuminate\Support\Facades\Http;
use Lorisleiva\Actions\Concerns\AsAction;

class CheckResaleLicense
{
    use AsAction;

    public function handle()
    {
        try {
            $settings = InstanceSettings::get();
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

            ray("Checking license key against $base_url/lemon/validate");
            $data = Http::withHeaders([
                'Accept' => 'application/json',
            ])->get("$base_url/lemon/validate", [
                'license_key' => $settings->resale_license,
                'instance_id' => $instance_id,
            ])->json();
            if (data_get($data, 'valid') === true && data_get($data, 'license_key.status') === 'active') {
                ray('Valid & active license key');
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
                ray('Activated license key');
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
            ray($e);
            $settings->update([
                'resale_license' => null,
                'is_resale_license_active' => false,
            ]);
            throw $e;
        }
    }
}
