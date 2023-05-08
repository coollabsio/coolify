<?php

namespace Database\Seeders;

use App\Models\Server;
use App\Models\Team;
use Illuminate\Database\Seeder;

class ServerSettingSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $server_2 = Server::find(1)->load(['settings']);
        $server_2->settings->is_build_server = true;
        $server_2->settings->is_validated = true;
        $server_2->settings->save();

        $server_3 = Server::find(2)->load(['settings']);
        $server_3->settings->is_build_server = false;
        $server_3->settings->is_validated = false;
        $server_3->settings->save();
    }
}
