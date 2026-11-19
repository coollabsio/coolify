<?php

use App\Models\Server;
use App\Models\User;
use Database\Seeders\SentinelSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('uses the configured development Sentinel URL for seeded servers', function () {
    DB::table('instance_settings')->insert(['id' => 0]);
    $user = User::factory()->create();
    $server = Server::factory()->create([
        'team_id' => $user->teams()->first()->id,
    ]);
    DB::table('server_settings')->where('id', $server->settings->id)->update([
        'sentinel_custom_url' => 'http://host.docker.internal:8000',
    ]);

    config()->set('app.env', 'local');
    config()->set('constants.sentinel.dev_url', 'https://coolify-dev.example.com:8000');

    app(SentinelSeeder::class)->run();

    expect($server->settings->fresh()->sentinel_custom_url)
        ->toBe('https://coolify-dev.example.com:8000');
});
