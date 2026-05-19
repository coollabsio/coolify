<?php

use App\Models\Server;
use Database\Seeders\CaSslCertSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('skips servers without private keys when seeding CA certificates', function () {
    Server::forceCreate([
        'name' => 'localhost',
        'ip' => 'host.docker.internal',
        'team_id' => 0,
        'private_key_id' => 0,
        'proxy' => [],
    ]);

    (new CaSslCertSeeder)->run();

    expect(Server::count())->toBe(1);
});
