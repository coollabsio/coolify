<?php

namespace Database\Seeders;

use App\Models\Server;
use Illuminate\Database\Seeder;
use InvalidArgumentException;

class ServerSettingSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $server_2 = Server::find(0)->load(['settings']);
        $server_2->settings->wildcard_domain = 'http://'.self::sslipHost();
        $server_2->settings->is_build_server = false;
        $server_2->settings->is_usable = true;
        $server_2->settings->is_reachable = true;
        $server_2->settings->save();
    }

    /**
     * The sslip.io host for seeded domains, based on DEV_SEED_IP (default 127.0.0.1).
     */
    public static function sslipHost(): string
    {
        $ip = config('constants.coolify.dev_seed_ip');

        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            throw new InvalidArgumentException("DEV_SEED_IP must be an IPv4 address, got: {$ip}");
        }

        return "{$ip}.sslip.io";
    }
}
