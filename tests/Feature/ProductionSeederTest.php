<?php

use App\Jobs\CoolifyTask;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\User;
use Database\Seeders\ProductionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('does not contact every server on start to write the CA certificate', function () {
    DB::table('instance_settings')->insert(['id' => 0]);
    $user = User::factory()->create();
    $team = $user->teams()->first();
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $team->id])->id,
    ]);

    config()->set('constants.coolify.self_hosted', false);
    Process::fake();
    Queue::fake();
    Storage::fake('ssh-keys');
    Storage::fake('ssh-mux');

    app(ProductionSeeder::class)->run();

    expect($server->sslCertificates()->where('is_ca_certificate', true)->exists())->toBeFalse();
    Queue::assertNotPushed(CoolifyTask::class);
});
