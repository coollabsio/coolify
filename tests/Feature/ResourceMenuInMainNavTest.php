<?php

use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\S3Storage;
use App\Models\ScheduledDatabaseBackup;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    InstanceSettings::unguarded(function () {
        InstanceSettings::query()->create([
            'id' => 0,
            'is_registration_enabled' => true,
        ]);
    });

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::query()->where('server_id', $this->server->id)->firstOrFail();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
    $this->application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'name' => 'Navbar Menu App',
        'status' => 'running',
    ]);
});

test('an application page teleports its menu into the main sidebar ', function () {
    $response = $this->get(route('project.application.configuration', [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
        'application_uuid' => $this->application->uuid,
    ]));

    $response->assertSuccessful();
    $html = $response->getContent();

    expect($html)
        ->toContain('id="main-nav-context-0"')
        ->toContain('x-teleport="#main-nav-context-0"')
        ->toContain('application-settings-navigation in-main-nav')
        ->toMatch('#<button type="button" @click="navLevel = -1; navSwap = \'back\'"\s+title="Back to the main menu"#')
        ->not->toContain('Back to the Navbar Menu App menu')
        ->not->toContain('id="main-nav-context-1"')
        ->and(substr_count($html, 'id="main-nav-context-0"'))->toBe(1);
});

test('pages without a resource menu keep the main menu on wide screens', function () {
    $response = $this->get(route('project.resource.index', ['project_uuid' => $this->project->uuid, 'environment_uuid' => $this->environment->uuid]));

    $response->assertSuccessful();
    expect($response->getContent())->not->toContain('id="main-nav-context-');
});

test('a database page teleports its menu into the main sidebar', function () {
    $database = StandalonePostgresql::create([
        'name' => 'orders-db', 'postgres_user' => 'postgres', 'postgres_password' => 'password', 'postgres_db' => 'db',
        'image' => 'postgres:17', 'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id, 'destination_type' => $this->destination->getMorphClass(),
    ]);

    $html = $this->get(route('project.database.configuration', [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
        'database_uuid' => $database->uuid,
    ]))->assertSuccessful()->getContent();

    expect($html)
        ->toContain('id="main-nav-context-0"')
        ->toContain('x-teleport="#main-nav-context-0"')
        ->toContain('application-settings-navigation in-main-nav');
});

test('a server page teleports its menu into the main sidebar with a back row to the main menu', function () {
    $html = $this->get(route('server.show', ['server_uuid' => $this->server->uuid]))
        ->assertSuccessful()
        ->getContent();

    expect($html)
        ->toContain('x-teleport="#main-nav-context-0"')
        ->toContain('application-settings-navigation in-main-nav')
        ->toMatch('#@click="navLevel = -1; navSwap = \'back\'"\s+title="Back to the main menu"#')
        ->and(substr_count($html, 'proxyConfigurationPending: '))->toBe(2);
});

test('integration detail pages teleport their menu with a back row to the integrations submenu', function () {
    $storage = S3Storage::create([
        'name' => 'backup-bucket', 'region' => 'us-east-1', 'key' => 'access-key', 'secret' => 'secret-key',
        'bucket' => 'coolify-backups', 'endpoint' => 'https://s3.example.com', 'team_id' => $this->team->id, 'is_usable' => true,
    ]);

    $pages = [
        route('storage.show', ['storage_uuid' => $storage->uuid]),
        route('destination.show', ['destination_uuid' => $this->destination->uuid]),
    ];

    foreach ($pages as $url) {
        expect($this->get($url)->assertSuccessful()->getContent())
            ->toContain('x-teleport="#main-nav-context-0"')
            ->toContain('application-settings-navigation in-main-nav')
            ->toMatch('#@click="navLevel = -1; navSwap = \'back\'"\s+title="Back to Integrations"#')
            ->toContain('data-active-submenu="Integrations"');
    }
});

test('a database backup page keeps the database menu one back away in the main sidebar', function () {
    $database = StandalonePostgresql::create([
        'name' => 'orders-db', 'postgres_user' => 'postgres', 'postgres_password' => 'password', 'postgres_db' => 'db',
        'image' => 'postgres:17', 'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id, 'destination_type' => $this->destination->getMorphClass(),
    ]);
    $backup = ScheduledDatabaseBackup::create([
        'frequency' => '0 0 * * *', 'save_s3' => false, 'database_id' => $database->id,
        'database_type' => $database->getMorphClass(), 'team_id' => $this->team->id,
    ]);

    $html = $this->get(route('project.database.backup.execution', [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
        'database_uuid' => $database->uuid,
        'backup_uuid' => $backup->uuid,
    ]))->assertSuccessful()->getContent();

    expect($html)
        ->toContain('x-teleport="#main-nav-context-0"')
        ->toContain('x-teleport="#main-nav-context-1"')
        ->toMatch('#@click="navLevel = -1; navSwap = \'back\'"\s+title="Back to the main menu"#')
        ->toMatch('#@click="navLevel = 0; navSwap = \'back\'"\s+title="Back to the database menu"#')
        ->not->toContain('Back to the orders-db menu')
        ->and(substr_count($html, 'aria-label="Database settings"'))->toBe(1)
        ->and(substr_count($html, 'aria-label="Backup settings"'))->toBe(2);
});
