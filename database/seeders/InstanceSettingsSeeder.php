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
            'smtp' => [
                'enabled' => true,
                'test_recipients' => 'test@example.com,test2@example.com',
                'host' => 'coolify-mail',
                'port' => 1025,
                'from_address' => 'hi@localhost.com',
                'from_name' => 'Coolify',
            ]
        ]);
        try {
            $ipv4 = Process::run('curl -4s https://ifconfig.io')->output();
            $ipv4 = trim($ipv4);
            $ipv4 = filter_var($ipv4, FILTER_VALIDATE_IP);
            $settings = InstanceSettings::get();
            if (is_null($settings->public_ipv4) && $ipv4) {
                $settings->update(['public_ipv4' => $ipv4]);
            }
            $ipv6 = Process::run('curl -6s https://ifconfig.io')->output();
            $ipv6 = trim($ipv6);
            $ipv6 = filter_var($ipv6, FILTER_VALIDATE_IP);
            $settings = InstanceSettings::get();
            if (is_null($settings->public_ipv6) && $ipv6) {
                $settings->update(['public_ipv6' => $ipv6]);
            }
        } catch (\Exception $e) {
            echo "Error: {$e->getMessage()}\n";
        }
    }
}
