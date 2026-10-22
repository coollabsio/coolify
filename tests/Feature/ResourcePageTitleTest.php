<?php

use App\Models\GithubApp;
use App\Models\InstanceSettings;
use App\Models\S3Storage;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0]));

    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->user->teams()->attach($this->team, ['role' => 'owner']);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

it('shows the source name and organization on the source page', function () {
    $githubApp = GithubApp::create([
        'name' => 'coolify-laravel-dev-public',
        'organization' => 'coollabsio',
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'custom_user' => 'git',
        'custom_port' => 22,
        'app_id' => 12345,
        'installation_id' => 67890,
        'client_id' => 'client-id',
        'client_secret' => 'client-secret',
        'webhook_secret' => 'webhook-secret',
        'team_id' => $this->team->id,
        'is_system_wide' => false,
    ]);

    $response = $this->get(route('source.github.show', ['github_app_uuid' => $githubApp->uuid]));

    $response->assertSuccessful();
    $response->assertSee('coolify-laravel-dev-public');
    $response->assertSee('GitHub App for coollabsio');
});

it('renders the team, notification, and key settings pages', function () {
    $team = $this->get(route('team.index'));
    $team->assertSuccessful();
    $team->assertSee('Manage your team, members, and access settings.');
    $team->assertSee('New team');

    $notifications = $this->get(route('notifications.email'));
    $notifications->assertSuccessful();
    $notifications->assertSee('Configure how your team receives deployment and system alerts.');

    $keys = $this->get(route('security.private-key.index'));
    $keys->assertSuccessful();
    $keys->assertSee('Manage SSH keys, cloud credentials, and API access tokens.');
    $keys->assertSee('Private Keys');
    $keys->assertSee('New private key');
});

it('shows the destination name and network server on the destination page', function () {
    $server = Server::factory()->create(['team_id' => $this->team->id, 'name' => 'prod-server']);
    $destination = StandaloneDocker::query()->where('server_id', $server->id)->firstOrFail();
    $destination->update(['name' => 'coolify']);

    $response = $this->get(route('destination.show', ['destination_uuid' => $destination->uuid]));

    $response->assertSuccessful();
    $response->assertSee('coolify');
    $response->assertSee('Docker network on prod-server');
});

it('shows the s3 storage name and description on the storage page', function () {
    $storage = S3Storage::create([
        'name' => 'backup-bucket',
        'description' => 'Primary backup destination',
        'region' => 'us-east-1',
        'key' => 'access-key',
        'secret' => 'secret-key',
        'bucket' => 'coolify-backups',
        'endpoint' => 'https://s3.example.com',
        'team_id' => $this->team->id,
        'is_usable' => true,
    ]);

    $response = $this->get(route('storage.show', ['storage_uuid' => $storage->uuid]));

    $response->assertSuccessful();
    $response->assertSee('backup-bucket');
    $response->assertSee('Primary backup destination');
});
