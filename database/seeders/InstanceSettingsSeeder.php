<?php

namespace Database\Seeders;

use App\Models\InstanceSettings;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Process;

class InstanceSettingsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        InstanceSettings::create([
            'id' => 0,
            'is_registration_enabled' => true,
            'is_resale_license_active' => true,
            'smtp_enabled' => true,
            'smtp_host' => 'coolify-mail',
            'smtp_port' => 1025,
            'smtp_from_address' => 'hi@localhost.com',
            'smtp_from_name' => 'Coolify',
        ]);
        try {
            $ipv4 = Process::run('curl -4s https://ifconfig.io')->output();
            $ipv4 = trim($ipv4);
            $ipv4 = filter_var($ipv4, FILTER_VALIDATE_IP);
            $settings = \App\Models\InstanceSettings::get();
            if (is_null($settings->public_ipv4) && $ipv4) {
                $settings->update(['public_ipv4' => $ipv4]);
            }
            $ipv6 = Process::run('curl -6s https://ifconfig.io')->output();
            $ipv6 = trim($ipv6);
            $ipv6 = filter_var($ipv6, FILTER_VALIDATE_IP);
            $settings = \App\Models\InstanceSettings::get();
            if (is_null($settings->public_ipv6) && $ipv6) {
                $settings->update(['public_ipv6' => $ipv6]);
            }
        } catch (\Throwable $e) {
            echo "Error: {$e->getMessage()}\n";
        }
    }
}
