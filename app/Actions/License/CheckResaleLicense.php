<?php

namespace App\Actions\License;

use App\Models\InstanceSettings;
use Illuminate\Support\Facades\Http;
use Visus\Cuid2\Cuid2;

class CheckResaleLicense
{
    public function __invoke()
    {
        try {
            $settings = InstanceSettings::get();
            $instance_id = config('app.id');
            if (!$settings->resale_license) {
                return;
            }
            ray('Checking license key');
            $data = Http::withHeaders([
                'Accept' => 'application/json',
            ])->post('https://api.lemonsqueezy.com/v1/licenses/validate', [
                'license_key' => $settings->resale_license,
                'instance_name' => $instance_id,
            ])->throw()->json();
            $product_id = (int)data_get($data, 'meta.product_id');
            $valid_product_id = (int)config('coolify.lemon_squeezy_product_id');
            if ($product_id !== $valid_product_id) {
                throw new \Exception('Invalid product id');
            }
            ray('Valid Product Id');

            ['valid' => $valid, 'license_key' => $license_key] = $data;

            if ($valid) {
                if (data_get($license_key, 'status') === 'inactive') {
                    Http::withHeaders([
                        'Accept' => 'application/json',
                    ])->post('https://api.lemonsqueezy.com/v1/licenses/activate', [
                        'license_key' => $settings->resale_license,
                        'instance_name' => $instance_id,
                    ])->throw()->json();
                }
                $settings->update([
                    'is_resale_license_active' => true,
                ]);
                return;
            }
            throw new \Exception('Invalid license key');
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
