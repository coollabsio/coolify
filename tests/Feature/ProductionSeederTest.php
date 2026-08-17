<?php

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Proxy\StartProxy;
use App\Models\Server;
use App\Models\SharedEnvironmentVariable;
use App\Models\SslCertificate;
use App\Models\Team;
use Database\Seeders\ProductionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('creates the root team before seeding the localhost server and predefined shared variables', function () {
    config([
        'broadcasting.default' => 'log',
        'constants.coolify.is_windows_docker_desktop' => true,
    ]);
    Queue::fake();
    StartProxy::shouldRun()->andReturn('OK');

    Server::creating(function (Server $server) {
        if ((int) $server->getKey() === 0) {
            expect(Team::find(0))->not->toBeNull();
        }
    });

    Server::created(function (Server $server) {
        SslCertificate::create([
            'server_id' => $server->id,
            'common_name' => 'Coolify CA Certificate',
            'ssl_certificate' => 'certificate',
            'ssl_private_key' => 'private-key',
            'valid_until' => now()->addYear(),
            'is_ca_certificate' => true,
        ]);
    });

    $this->seed(ProductionSeeder::class);

    $rootTeam = Team::find(0);
    $localhostServer = Server::find(0);

    expect($rootTeam)->not->toBeNull()
        ->and($localhostServer)->not->toBeNull()
        ->and($localhostServer->team_id)->toBe(0);

    expect(SharedEnvironmentVariable::query()
        ->where('type', 'server')
        ->where('server_id', 0)
        ->where('team_id', 0)
        ->pluck('key')
        ->all()
    )->toContain('COOLIFY_SERVER_UUID', 'COOLIFY_SERVER_NAME');

    instanceSettings()->update(['is_registration_enabled' => true]);

    $rootUser = app(CreateNewUser::class)->create([
        'name' => 'Root User',
        'email' => 'root@example.com',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
    ]);

    expect(Team::whereKey(0)->count())->toBe(1)
        ->and($rootUser->teams()->where('team_id', 0)->exists())->toBeTrue();
});
