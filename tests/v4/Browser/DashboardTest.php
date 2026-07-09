<?php

use App\Enums\ProxyStatus;
use App\Enums\ProxyTypes;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::create(['id' => 0]);

    $this->user = User::factory()->create([
        'id' => 0,
        'name' => 'Root User',
        'email' => 'test@example.com',
        'password' => Hash::make('password'),
    ]);

    PrivateKey::create([
        'id' => 1,
        'uuid' => 'ssh-test',
        'team_id' => 0,
        'name' => 'Test Key',
        'description' => 'Test SSH key',
        'private_key' => '-----BEGIN OPENSSH PRIVATE KEY-----\nb3BlbnNzaC1rZXktdjEAAAAABG5vbmUAAAAEbm9uZQAAAAAAAAABAAAAMwAAAAtzc2gtZW\nQyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevAAAAJi/QySHv0Mk\nhwAAAAtzc2gtZWQyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevA\nAAAECBQw4jg1WRT2IGHMncCiZhURCts2s24HoDS0thHnnRKVuGmoeGq/pojrsyP1pszcNV\nuZx9iFkCELtxrh31QJ68AAAAEXNhaWxANzZmZjY2ZDJlMmRkAQIDBA==\n-----END OPENSSH PRIVATE KEY-----',
    ]);

    Server::create([
        'id' => 0,
        'uuid' => 'localhost',
        'name' => 'localhost',
        'description' => 'This is a test docker container in development mode',
        'ip' => 'coolify-testing-host',
        'team_id' => 0,
        'private_key_id' => 1,
        'proxy' => [
            'type' => ProxyTypes::TRAEFIK->value,
            'status' => ProxyStatus::EXITED->value,
        ],
    ]);

    Project::create([
        'uuid' => 'project-1',
        'name' => 'My first project',
        'description' => 'This is a test project in development',
        'team_id' => 0,
    ]);
});

function loginAndSkipOnboarding(): mixed
{
    return visit('/login')
        ->fill('email', 'test@example.com')
        ->fill('password', 'password')
        ->click('Login')
        ->click('Skip Setup');
}

it('shows project and server kpis on dashboard', function () {
    $page = loginAndSkipOnboarding();

    $page->assertSee('Projects')
        ->assertSee('Servers')
        ->assertSee('Applications')
        ->assertSee('Services')
        ->assertSee('Databases')
        ->assertSee('Active / Inactive');
});

it('shows latest deployments section on dashboard', function () {
    $page = loginAndSkipOnboarding();

    $page->assertSee('Latest Deployments')
        ->assertSee('Connected Servers');
});
