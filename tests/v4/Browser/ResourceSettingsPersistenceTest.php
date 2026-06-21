<?php

use App\Enums\ProxyStatus;
use App\Enums\ProxyTypes;
use App\Models\Application;
use App\Models\DiscordNotificationSettings;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Visus\Cuid2\Cuid2;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::create(['id' => 0, 'is_sponsorship_popup_enabled' => false]);

    $this->user = User::factory()->create([
        'id' => 0,
        'name' => 'Root User',
        'email' => 'test@example.com',
        'password' => Hash::make('password'),
    ]);

    DiscordNotificationSettings::where('team_id', 0)->update([
        'discord_enabled' => true,
        'discord_webhook_url' => 'https://discord.com/test',
    ]);

    PrivateKey::create([
        'id' => 1,
        'uuid' => 'ssh-test',
        'team_id' => 0,
        'name' => 'Test Key',
        'description' => 'Test SSH key',
        'private_key' => '-----BEGIN OPENSSH PRIVATE KEY-----
b3BlbnNzaC1rZXktdjEAAAAABG5vbmUAAAAEbm9uZQAAAAAAAAABAAAAMwAAAAtzc2gtZW
QyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevAAAAJi/QySHv0Mk
hwAAAAtzc2gtZWQyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevA
AAAECBQw4jg1WRT2IGHMncCiZhURCts2s24HoDS0thHnnRKVuGmoeGq/pojrsyP1pszcNV
uZx9iFkCELtxrh31QJ68AAAAEXNhaWxANzZmZjY2ZDJlMmRkAQIDBA==
-----END OPENSSH PRIVATE KEY-----',
    ]);

    $this->server = Server::create([
        'id' => 0,
        'uuid' => 'localhost',
        'name' => 'localhost',
        'description' => 'Test docker container in development',
        'ip' => 'coolify-testing-host',
        'team_id' => 0,
        'private_key_id' => 1,
        'proxy' => [
            'type' => ProxyTypes::TRAEFIK->value,
            'status' => ProxyStatus::EXITED->value,
        ],
    ]);

    $this->project = Project::create([
        'uuid' => 'project-resource-persistence',
        'name' => 'Resource Persistence',
        'description' => 'Browser persistence tests',
        'team_id' => 0,
    ]);

    $this->environment = $this->project->environments()->first();

    StandaloneDocker::withoutEvents(function () {
        $this->destination = StandaloneDocker::firstOrCreate(
            ['server_id' => $this->server->id, 'network' => 'coolify'],
            ['uuid' => 'docker-destination-1', 'name' => 'docker-destination-1']
        );
    });

    $this->application = Application::factory()->create([
        'uuid' => 'app-resource-persistence',
        'name' => 'App Before Browser Save',
        'git_repository' => 'https://github.com/coollabsio/coolify.git',
        'git_branch' => 'main',
        'build_pack' => 'nixpacks',
        'ports_exposes' => '3000',
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);

    $this->database = StandalonePostgresql::create([
        'uuid' => 'db-resource-persistence',
        'name' => 'Database Before Browser Save',
        'description' => 'Initial database description',
        'postgres_user' => 'postgres',
        'postgres_password' => 'postgres-password',
        'postgres_db' => 'postgres',
        'image' => 'postgres:15-alpine',
        'status' => 'exited',
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);
});

it('saves application name and enables static site with nginx config', function () {
    loginAndSkipBoarding();

    $updatedName = 'App Saved '.(string) new Cuid2;
    $applicationRoute = "/project/{$this->project->uuid}/environment/{$this->environment->uuid}/application/{$this->application->uuid}";

    $page = visit($applicationRoute);
    $page->screenshot();

    $page->assertSee('General')
        ->assertDontSee('Custom Nginx Configuration')
        ->fill('name', $updatedName)
        ->fill('customDockerRunOptions', '--read-only');

    submitLivewireForm($page);
    $page->click('[id^="isStatic"]')
        ->wait(2)
        ->screenshot();

    $page->assertSourceHas('Custom Nginx Configuration')
        ->assertSee('Is it a SPA (Single Page Application)?')
        ->assertValue('name', $updatedName);

    $this->application->refresh();
    expect($this->application->name)->toBe($updatedName)
        ->and($this->application->custom_docker_run_options)->toBe('--read-only')
        ->and($this->application->settings->is_static)->toBeTrue();

    $reloadedPage = visit($applicationRoute);
    $reloadedPage->screenshot();

    $reloadedPage->assertValue('name', $updatedName)
        ->assertSourceHas('Custom Nginx Configuration')
        ->assertSourceHas('Is it a SPA (Single Page Application)?');
});

it('saves database name and enables ssl with mode selector', function () {
    loginAndSkipBoarding();

    $updatedDatabaseName = 'Database Saved '.(string) new Cuid2;
    $databaseRoute = "/project/{$this->project->uuid}/environment/{$this->environment->uuid}/database/{$this->database->uuid}";

    $page = visit($databaseRoute);
    $page->screenshot();

    $page->assertSee('General')
        ->assertDontSee('SSL Mode')
        ->fill('name', $updatedDatabaseName)
        ->fill('description', 'Updated by browser test');

    submitLivewireForm($page);
    $page->click('[id^="enableSsl"]');

    $page->assertSee('SSL Mode')
        ->assertValue('name', $updatedDatabaseName);
    $page->screenshot();

    $this->database->refresh();
    expect($this->database->name)->toBe($updatedDatabaseName)
        ->and($this->database->description)->toBe('Updated by browser test')
        ->and($this->database->enable_ssl)->toBeTruthy();

    $reloadedPage = visit($databaseRoute);
    $reloadedPage->screenshot();

    $reloadedPage->assertValue('name', $updatedDatabaseName)
        ->assertSee('SSL Mode');
});

function submitLivewireForm($page): void
{
    $page->script("document.querySelector('form[wire\\\\:submit=\"submit\"]')?.requestSubmit()");
    $page->wait(1);
}
