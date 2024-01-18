<?php

namespace Database\Seeders;

use App\Models\Server;
use Illuminate\Database\Seeder;

class ServerSettingSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $server_2 = Server::find(0)->load(['settings']);
        $server_2->settings->wildcard_domain = 'http://127.0.0.1.sslip.io';
        $server_2->settings->is_build_server = false;
        $server_2->settings->is_usable = true;
        $server_2->settings->is_reachable = true;
        $server_2->settings->save();
    }
}
