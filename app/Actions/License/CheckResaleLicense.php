<?php

namespace App\Actions\License;

use App\Models\InstanceSettings;
use Illuminate\Support\Facades\Http;

class CheckResaleLicense
{
    public function __invoke()
    {
        try {
            $settings = InstanceSettings::get();
            $settings->update([
                'is_resale_license_active' => false,
            ]);
            if (!$settings->resale_license) {
                return;
            }
            $base_url = config('coolify.license_url');
            if (is_dev()) {
                $base_url = 'http://host.docker.internal:8787';
            }
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
        } catch (\Throwable $th) {
            ray($th);
            $settings->update([
                'resale_license' => null,
                'is_resale_license_active' => false,
            ]);
            throw $th;
        }
    }
}
