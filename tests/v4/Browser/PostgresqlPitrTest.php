<?php

use App\Enums\ProxyStatus;
use App\Enums\ProxyTypes;
use App\Models\InstanceSettings;
use App\Models\PostgresqlWalBackupConfiguration;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\S3Storage;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::create([
        'id' => 0,
        'is_sponsorship_popup_enabled' => false,
    ]));

    $this->user = User::factory()->create([
        'id' => 0,
        'name' => 'Root User',
        'email' => 'test@example.com',
        'password' => Hash::make('password'),
    ]);

    PrivateKey::create([
        'id' => 1,
        'uuid' => 'pitr-browser-key',
        'team_id' => 0,
        'name' => 'PITR Browser Key',
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
        'uuid' => 'pitr-browser-server',
        'name' => 'PITR Browser Server',
        'ip' => 'coolify-testing-host',
        'team_id' => 0,
        'private_key_id' => 1,
        'proxy' => [
            'type' => ProxyTypes::TRAEFIK->value,
            'status' => ProxyStatus::EXITED->value,
        ],
    ]);
    $this->server->settings()->update([
        'is_reachable' => true,
        'is_usable' => true,
        'force_disabled' => false,
    ]);

    $this->project = Project::create([
        'uuid' => 'pitr-browser-project',
        'name' => 'PITR Browser Project',
        'team_id' => 0,
    ]);
    $this->environment = $this->project->environments()->firstOrFail();

    StandaloneDocker::withoutEvents(function () {
        $this->destination = StandaloneDocker::firstOrCreate(
            ['server_id' => $this->server->id, 'network' => 'coolify'],
            ['uuid' => 'pitr-browser-destination', 'name' => 'PITR Browser Destination'],
        );
    });

    $this->primaryStorage = createPostgresqlPitrBrowserStorage('Primary PITR Storage', 'primary');
    $this->secondaryStorage = createPostgresqlPitrBrowserStorage('Secondary PITR Storage', 'secondary');
    $this->database = createPostgresqlPitrBrowserDatabase(
        $this->environment->id,
        $this->destination,
        'pitr-browser-database',
        'ghcr.io/coollabsio/postgres-walg:16',
    );
    $this->configuration = PostgresqlWalBackupConfiguration::create([
        'team_id' => 0,
        'standalone_postgresql_id' => $this->database->id,
        's3_storage_id' => $this->primaryStorage->id,
        'enabled' => true,
        'postgres_major_version' => 16,
        'status' => 'warning',
    ]);
    $this->regularDatabase = createPostgresqlPitrBrowserDatabase(
        $this->environment->id,
        $this->destination,
        'regular-browser-database',
        'postgres:16-alpine',
    );
});

it('loads PITR controls, requires restore fields, locks the image, and keeps Backups available', function () {
    loginAndSkipBoarding();

    $page = visit(postgresqlPitrBrowserUrl($this, $this->database));
    $page->assertSee('Point-in-Time Recovery')
        ->assertSee('Run Base Backup Now')
        ->assertSee('Restore to Timestamp')
        ->assertAttribute('restoreTargetTime', 'required', '')
        ->assertAttribute('restoreName', 'required', '')
        ->assertNoJavaScriptErrors();

    $page = visit(postgresqlPitrBrowserConfigurationUrl($this, $this->database));
    $page->assertDisabled('image')
        ->assertNoJavaScriptErrors();

    $page = visit(postgresqlPitrBrowserBackupsUrl($this, $this->database));
    $page->assertSee('Scheduled Backups')
        ->assertSee('Point-in-Time Recovery')
        ->assertNoJavaScriptErrors()
        ->screenshot();
});

it('reattaches S3 storage and saves PITR settings as pending restart', function () {
    $this->configuration->update([
        's3_storage_id' => null,
        'enabled' => false,
        'status' => 'failed',
        'last_base_backup_at' => now()->subHours(2),
        'last_successful_base_backup_at' => now()->subHour(),
    ]);
    loginAndSkipBoarding();

    $page = visit(postgresqlPitrBrowserUrl($this, $this->database));
    $page->select('s3StorageUuid', $this->secondaryStorage->uuid)
        ->fill('baseBackupFrequency', 'hourly')
        ->click('Save Settings')
        ->assertSee('Pending Restart')
        ->assertNoJavaScriptErrors();

    $configuration = $this->configuration->fresh();
    expect($configuration->s3_storage_id)->toBe($this->secondaryStorage->id)
        ->and($configuration->enabled)->toBeTrue()
        ->and($configuration->status)->toBe('pending_restart')
        ->and($configuration->last_base_backup_at)->toBeNull()
        ->and($configuration->last_successful_base_backup_at)->toBeNull();

    $page->screenshot();
});

it('does not expose the PITR page for regular PostgreSQL', function () {
    loginAndSkipBoarding();

    $page = visit(postgresqlPitrBrowserConfigurationUrl($this, $this->regularDatabase));
    $page->assertDontSee('Point-in-Time Recovery')
        ->assertNoJavaScriptErrors();

    $page = visit(postgresqlPitrBrowserUrl($this, $this->regularDatabase));
    $page->assertSee('404')
        ->assertNoJavaScriptErrors()
        ->screenshot();
});

it('requires S3 storage before PITR creation can continue', function () {
    loginAndSkipBoarding();

    $url = "/project/{$this->project->uuid}/environment/{$this->environment->uuid}/new"
        ."?type=postgresql&server_id={$this->server->id}&destination={$this->destination->uuid}";
    $page = visit($url);
    $page->click('PostgreSQL with Point-in-Time Recovery')
        ->assertSee('Configure Point-in-Time Recovery')
        ->assertValue('pitr_s3_storage_uuid', '')
        ->click('Create PostgreSQL with PITR')
        ->assertSee('The pitr s3 storage uuid field is required.')
        ->screenshot();
});

function postgresqlPitrBrowserUrl(object $test, StandalonePostgresql $database): string
{
    return "/project/{$test->project->uuid}/environment/{$test->environment->uuid}/database/{$database->uuid}/point-in-time-recovery";
}

function postgresqlPitrBrowserConfigurationUrl(object $test, StandalonePostgresql $database): string
{
    return "/project/{$test->project->uuid}/environment/{$test->environment->uuid}/database/{$database->uuid}";
}

function postgresqlPitrBrowserBackupsUrl(object $test, StandalonePostgresql $database): string
{
    return "/project/{$test->project->uuid}/environment/{$test->environment->uuid}/database/{$database->uuid}/backups";
}

function createPostgresqlPitrBrowserStorage(string $name, string $suffix): S3Storage
{
    return S3Storage::create([
        'team_id' => 0,
        'name' => $name,
        'region' => 'us-east-1',
        'key' => 'access-key',
        'secret' => 'top-secret',
        'bucket' => "pitr-browser-{$suffix}",
        'endpoint' => 'https://s3.example.com',
        'is_usable' => true,
    ]);
}

function createPostgresqlPitrBrowserDatabase(
    int $environmentId,
    StandaloneDocker $destination,
    string $uuid,
    string $image,
): StandalonePostgresql {
    return StandalonePostgresql::create([
        'uuid' => $uuid,
        'name' => str($uuid)->headline()->toString(),
        'image' => $image,
        'postgres_user' => 'postgres',
        'postgres_password' => 'password',
        'postgres_db' => 'postgres',
        'status' => 'running:healthy',
        'environment_id' => $environmentId,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
}
