<?php

use App\Actions\Database\StopDatabaseProxy;
use App\Livewire\Project\Database\Sqlite\ConnectApplication;
use App\Livewire\Project\Service\Domains;
use App\Livewire\Project\Service\FileStorage;
use App\Livewire\Project\Service\Index;
use App\Livewire\Project\Service\Storage;
use App\Livewire\Project\Shared\Storages\All;
use App\Models\Application;
use App\Models\AuditEvent;
use App\Models\DnsProviderZone;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\IntegrationToken;
use App\Models\LocalFileVolume;
use App\Models\LocalPersistentVolume;
use App\Models\ManagedDnsRecord;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneSqlite;
use App\Models\Team;
use App\Models\User;
use App\Services\Dns\CloudflareDnsProvider;
use App\Services\Dns\ManagedDnsRecordCleanup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    $this->withoutDefer();
    config()->set('app.maintenance.store', 'array');
    Bus::fake();
    // Remote `test -f` / `test -d` probes report a missing path; path confinement checks pass.
    Process::fake(fn ($process) => Process::result(
        output: str_contains(implode(' ', (array) $process->command), '&& echo OK || echo NOK') ? 'NOK' : 'OK'
    ));
    Server::flushIdentityMap();

    InstanceSettings::unguarded(fn () => InstanceSettings::updateOrCreate(
        ['id' => 0],
        ['id' => 0, 'is_dns_validation_enabled' => false, 'is_api_enabled' => true],
    ));

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $keyId = DB::table('private_keys')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'name' => 'Test Key',
        'private_key' => Crypt::encryptString('test-key'),
        'team_id' => $this->team->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $keyId,
        'ip' => '203.0.113.10',
    ]);
    $this->destination = StandaloneDocker::withoutEvents(fn () => StandaloneDocker::firstOrCreate(
        ['server_id' => $this->server->id, 'network' => 'coolify'],
        ['uuid' => (string) Str::uuid(), 'name' => 'test-docker'],
    ));
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);

    $this->application = Application::factory()->create([
        'uuid' => (string) Str::uuid(),
        'name' => 'Audit App',
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'build_pack' => 'dockerimage',
        'docker_registry_image_name' => 'nginx',
    ]);

    $this->service = Service::factory()->create([
        'environment_id' => $this->environment->id,
        'server_id' => $this->server->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'docker_compose_raw' => "services:\n  web:\n    image: nginx:alpine\n  postgres:\n    image: postgres:17\n",
    ]);
    $this->serviceApplication = ServiceApplication::create([
        'uuid' => (string) Str::uuid(),
        'name' => 'web',
        'service_id' => $this->service->id,
        'image' => 'nginx:alpine',
        'fqdn' => 'https://web.example.com',
    ]);
    $this->serviceDatabase = ServiceDatabase::create([
        'uuid' => (string) Str::uuid(),
        'name' => 'postgres',
        'service_id' => $this->service->id,
        'image' => 'postgres:17',
    ]);
});

function storageServiceAuditEvent(string $event): ?AuditEvent
{
    return AuditEvent::query()->where('event', $event)->latest('id')->first();
}

function storageServiceAuditFileVolume(Application $application, array $attributes = []): LocalFileVolume
{
    return LocalFileVolume::create([
        'fs_path' => application_configuration_dir().'/'.$application->uuid.'/app.conf',
        'mount_path' => '/etc/app.conf',
        'content' => 'original-file-body',
        'is_directory' => false,
        'is_based_on_git' => false,
        'is_preview_suffix_enabled' => true,
        'resource_id' => $application->id,
        'resource_type' => $application->getMorphClass(),
        ...$attributes,
    ]);
}

test('creating storages records storage_created for the parent resource type without file content', function () {
    Livewire::test(Storage::class, ['resource' => $this->application])
        ->set('name', 'data')
        ->set('mount_path', '/app/data')
        ->call('submitPersistentVolume')
        ->assertDispatched('success');

    Livewire::test(Storage::class, ['resource' => $this->application])
        ->set('file_storage_path', '/etc/nginx/nginx.conf')
        ->set('file_storage_content', 'super-secret-file-body')
        ->call('submitFileStorage')
        ->assertDispatched('success');

    Livewire::test(Storage::class, ['resource' => $this->serviceApplication])
        ->set('host_file_storage_source', '/etc/hosts')
        ->set('host_file_storage_destination', '/etc/hosts')
        ->call('submitHostFileStorage')
        ->assertDispatched('success');

    $events = AuditEvent::query()->where('event', 'ui.application.storage_created')->orderBy('id')->get();
    expect($events)->toHaveCount(2)
        ->and($events[0]->team_id)->toBe($this->team->id)
        ->and($events[0]->resource_uuid)->toBe($this->application->uuid)
        ->and($events[0]->metadata)->toMatchArray([
            'application_uuid' => $this->application->uuid,
            'storage_type' => 'persistent',
            'storage_name' => $this->application->uuid.'-data',
            'mount_path' => '/app/data',
        ])
        ->and($events[1]->metadata)->toMatchArray(['storage_type' => 'file', 'mount_path' => '/etc/nginx/nginx.conf']);

    $serviceEvent = storageServiceAuditEvent('ui.service.storage_created');
    expect($serviceEvent->resource_uuid)->toBe($this->service->uuid)
        ->and($serviceEvent->metadata)->toMatchArray([
            'service_application_uuid' => $this->serviceApplication->uuid,
            'storage_type' => 'host_file',
            'host_path' => '/etc/hosts',
        ]);

    expect(AuditEvent::query()->get()->toJson())->not->toContain('super-secret-file-body');
});

test('creating a database volume records database storage_created', function () {
    $database = StandalonePostgresql::create([
        'name' => 'audit-postgres',
        'image' => 'postgres:15-alpine',
        'postgres_user' => 'postgres',
        'postgres_password' => 'password',
        'postgres_db' => 'postgres',
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);

    Livewire::test(Storage::class, ['resource' => $database])
        ->set('name', 'extra')
        ->set('mount_path', '/extra')
        ->call('submitPersistentVolume')
        ->assertDispatched('success');

    expect(storageServiceAuditEvent('ui.database.storage_created')->metadata)->toMatchArray([
        'database_uuid' => $database->uuid,
        'storage_type' => 'persistent',
        'mount_path' => '/extra',
    ]);
});

test('updating file content records changed field names without the content', function () {
    $file = storageServiceAuditFileVolume($this->application);

    Livewire::test(FileStorage::class, ['fileStorage' => $file])
        ->set('content', 'new-secret-file-body')
        ->call('submit')
        ->assertDispatched('success');

    $event = storageServiceAuditEvent('ui.application.storage_updated');
    expect($event->metadata)->toMatchArray([
        'storage_uuid' => $file->uuid,
        'storage_type' => 'file',
        'changed_fields' => ['content'],
    ]);
    expect(AuditEvent::query()->get()->toJson())
        ->not->toContain('new-secret-file-body')
        ->not->toContain('original-file-body');
});

test('saving a file storage without changes records no audit event', function () {
    $file = storageServiceAuditFileVolume($this->application);

    Livewire::test(FileStorage::class, ['fileStorage' => $file])
        ->call('instantSave')
        ->assertDispatched('success');

    expect(AuditEvent::query()->where('event', 'like', '%.storage_updated')->exists())->toBeFalse();
});

test('converting and deleting a file storage records storage events', function () {
    $file = storageServiceAuditFileVolume($this->application);

    $component = Livewire::test(FileStorage::class, ['fileStorage' => $file])
        ->call('convertToDirectory');

    expect(storageServiceAuditEvent('ui.application.storage_updated')->metadata)->toMatchArray([
        'operation' => 'converted_to_directory',
        'storage_type' => 'directory',
    ])->and(storageServiceAuditEvent('ui.application.storage_updated')->metadata['changed_fields'])->toContain('is_directory');

    $component->call('convertToFile');
    expect(storageServiceAuditEvent('ui.application.storage_updated')->metadata)->toMatchArray([
        'operation' => 'converted_to_file',
        'storage_type' => 'file',
    ]);

    Livewire::test(FileStorage::class, ['fileStorage' => $file->fresh()])
        ->call('delete', 'password', ['permanently_delete'])
        ->assertDispatched('success');

    expect($file->fresh())->toBeNull()
        ->and(storageServiceAuditEvent('ui.application.storage_deleted')->metadata)->toMatchArray([
            'storage_uuid' => $file->uuid,
            'mount_path' => '/etc/app.conf',
            'deleted_from_server' => true,
        ]);
});

test('updating and deleting a persistent volume records storage events and skips unchanged saves', function () {
    $volume = LocalPersistentVolume::create([
        'name' => $this->application->uuid.'-data',
        'mount_path' => '/app/data',
        'resource_id' => $this->application->id,
        'resource_type' => $this->application->getMorphClass(),
    ]);

    $component = Livewire::test(All::class, ['resource' => $this->application])
        ->call('submit', $volume->id);

    expect(AuditEvent::query()->where('event', 'ui.application.storage_updated')->exists())->toBeFalse();

    $component->set("forms.{$volume->id}.mountPath", '/app/other')
        ->call('submit', $volume->id)
        ->assertDispatched('success');

    expect(storageServiceAuditEvent('ui.application.storage_updated')->metadata)->toMatchArray([
        'storage_id' => $volume->id,
        'mount_path' => '/app/other',
        'changed_fields' => ['mount_path'],
    ]);

    $component->call('delete', $volume->id, 'password');

    expect($volume->fresh())->toBeNull()
        ->and(storageServiceAuditEvent('ui.application.storage_deleted')->metadata)->toMatchArray([
            'storage_id' => $volume->id,
            'storage_type' => 'persistent',
            'docker_volume_deleted' => false,
        ]);
});

test('connecting and unlinking a sqlite volume records application storage events', function () {
    $this->server->settings()->update(['is_reachable' => true, 'is_usable' => true]);
    $sqlite = StandaloneSqlite::create([
        'name' => 'app-sqlite',
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);

    Livewire::test(ConnectApplication::class, ['database' => $sqlite])
        ->set('applicationUuid', $this->application->uuid)
        ->set('mountPath', '/app/database')
        ->call('connect')
        ->assertHasNoErrors();

    $volume = $this->application->persistentStorages()->sole();
    expect(storageServiceAuditEvent('ui.application.storage_created')->metadata)->toMatchArray([
        'application_uuid' => $this->application->uuid,
        'standalone_sqlite_uuid' => $sqlite->uuid,
        'storage_id' => $volume->id,
        'mount_path' => '/app/database',
    ]);

    Livewire::test(ConnectApplication::class, ['database' => $sqlite])
        ->call('unlink', $volume->id);

    expect(storageServiceAuditEvent('ui.application.storage_deleted')->metadata)->toMatchArray([
        'standalone_sqlite_uuid' => $sqlite->uuid,
        'storage_id' => $volume->id,
    ]);
});

test('updating a service database records changed fields and skips unchanged saves', function () {
    $component = Livewire::test(Index::class, ['serviceApplication' => $this->serviceDatabase->fresh(), 'embedded' => true])
        ->call('submitDatabase');

    expect(AuditEvent::query()->where('event', 'ui.service_database.updated')->exists())->toBeFalse();

    $component->set('humanName', 'Primary DB')
        ->call('submitDatabase')
        ->assertDispatched('success', 'Database saved.');

    $event = storageServiceAuditEvent('ui.service_database.updated');
    expect($event->resource_uuid)->toBe($this->serviceDatabase->uuid)
        ->and($event->team_id)->toBe($this->team->id)
        ->and($event->metadata)->toMatchArray([
            'service_uuid' => $this->service->uuid,
            'changed_fields' => ['human_name'],
        ]);
});

test('disabling public access of a service database records is_public and public_port', function () {
    StopDatabaseProxy::shouldRun()->once();
    $this->serviceDatabase->update(['is_public' => true, 'public_port' => 15432]);

    Livewire::test(Index::class, ['serviceApplication' => $this->serviceDatabase->fresh(), 'embedded' => true])
        ->call('disablePublicAccess')
        ->assertDispatched('success');

    expect(storageServiceAuditEvent('ui.service_database.updated')->metadata)->toMatchArray([
        'service_database_uuid' => $this->serviceDatabase->uuid,
        'changed_fields' => ['is_public'],
        'is_public' => false,
        'public_port' => 15432,
    ]);
});

test('updating, converting and deleting service applications records service application events', function () {
    $component = Livewire::test(Index::class, ['serviceApplication' => $this->serviceApplication->fresh(), 'embedded' => true])
        ->set('isGzipEnabled', false)
        ->call('instantSaveApplicationSettings')
        ->assertDispatched('success');

    expect(storageServiceAuditEvent('ui.service_application.updated')->metadata)->toMatchArray([
        'service_application_uuid' => $this->serviceApplication->uuid,
    ])->and(storageServiceAuditEvent('ui.service_application.updated')->metadata['changed_fields'])->toContain('is_gzip_enabled');

    $component->set('maxRestartCount', 7)->call('saveMaxRestartCount');
    expect(storageServiceAuditEvent('ui.service_application.updated')->metadata['changed_fields'])->toContain('max_restart_count');

    $component->call('convertToDatabase');
    $convertedDatabase = $this->service->databases()->where('name', 'web')->sole();
    expect(storageServiceAuditEvent('ui.service_application.converted_to_database')->metadata)->toMatchArray([
        'service_application_uuid' => $this->serviceApplication->uuid,
        'service_database_uuid' => $convertedDatabase->uuid,
    ]);

    Livewire::test(Index::class, ['serviceApplication' => $convertedDatabase, 'embedded' => true])
        ->call('convertToApplication');
    $convertedApplication = $this->service->applications()->where('name', 'web')->sole();
    expect(storageServiceAuditEvent('ui.service_database.converted_to_application')->metadata)->toMatchArray([
        'service_database_uuid' => $convertedDatabase->uuid,
        'service_application_uuid' => $convertedApplication->uuid,
    ]);

    Livewire::test(Index::class, ['serviceApplication' => $convertedApplication, 'embedded' => true])
        ->call('deleteApplication', 'password');
    Livewire::test(Index::class, ['serviceApplication' => $this->serviceDatabase->fresh(), 'embedded' => true])
        ->call('deleteDatabase', 'password');

    expect(storageServiceAuditEvent('ui.service_application.deleted')->resource_uuid)->toBe($convertedApplication->uuid)
        ->and(storageServiceAuditEvent('ui.service_database.deleted')->resource_uuid)->toBe($this->serviceDatabase->uuid);
});

test('saving service application domains records service application updates', function () {
    Queue::fake();

    $component = Livewire::test(Domains::class, ['service' => $this->service->fresh(['applications', 'server'])])
        ->call('updateForceHttps', $this->serviceApplication->id, false)
        ->assertHasNoErrors();

    expect(storageServiceAuditEvent('ui.service_application.updated')->metadata)->toMatchArray([
        'service_uuid' => $this->service->uuid,
        'service_application_uuid' => $this->serviceApplication->uuid,
        'changed_fields' => ['is_force_https_enabled'],
    ]);

    $component->set('newServiceApplicationId', $this->serviceApplication->id)
        ->set('newDomain', 'https://second.example.com')
        ->call('addDomain')
        ->assertHasNoErrors();

    expect($this->serviceApplication->fresh()->fqdn)->toContain('https://second.example.com')
        ->and(storageServiceAuditEvent('ui.service_application.updated')->metadata['changed_fields'])->toContain('fqdn');
});

test('the service application API update records changed fields', function () {
    auth()->forgetGuards();
    $plainTextToken = Str::random(40);
    $token = $this->user->tokens()->create([
        'name' => 'audit-token',
        'token' => hash('sha256', $plainTextToken),
        'abilities' => ['*'],
        'team_id' => $this->team->id,
    ]);

    $this->withToken($token->getKey().'|'.$plainTextToken)
        ->patchJson("/api/v1/services/{$this->service->uuid}/applications/{$this->serviceApplication->uuid}", [
            'human_name' => 'Web UI',
        ])
        ->assertOk();

    $event = storageServiceAuditEvent('api.service_application.updated');
    expect($event->team_id)->toBe($this->team->id)
        ->and($event->metadata)->toMatchArray([
            'service_uuid' => $this->service->uuid,
            'service_application_uuid' => $this->serviceApplication->uuid,
            'changed_fields' => ['human_name'],
        ]);
});

test('forgetting a managed dns record without touching the provider is audited', function () {
    $integrationToken = IntegrationToken::factory()->for($this->team)->create(['provider' => 'cloudflare', 'token' => 'secret']);
    $zone = DnsProviderZone::factory()->for($integrationToken)->create(['provider_zone_id' => 'zone-1', 'name' => 'example.com']);
    $notOwned = ManagedDnsRecord::factory()->for($zone, 'zone')->create(['name' => 'hand.example.com']);
    $kept = ManagedDnsRecord::factory()->owned()->for($zone, 'zone')->create(['name' => 'kept.example.com']);
    Http::fake();

    $cleanup = app(ManagedDnsRecordCleanup::class);
    $cleanup->release($notOwned, [], true);
    $cleanup->release($kept, [], false);

    $events = AuditEvent::query()->where('event', 'ui.dns_record.delete_skipped')->orderBy('id')->get();
    expect($notOwned->fresh())->toBeNull()
        ->and($kept->fresh())->toBeNull()
        ->and($events->map(fn (AuditEvent $event) => [$event->metadata['hostname'], $event->metadata['reason']])->all())->toBe([
            ['hand.example.com', 'not_owned'],
            ['kept.example.com', 'kept_by_user'],
        ]);
    Http::assertNothingSent();
});

test('forgetting an owned dns record that is already gone at the provider is audited', function () {
    $integrationToken = IntegrationToken::factory()->for($this->team)->create(['provider' => 'cloudflare', 'token' => 'secret']);
    $zone = DnsProviderZone::factory()->for($integrationToken)->create(['provider_zone_id' => 'zone-1', 'name' => 'example.com']);
    $record = ManagedDnsRecord::factory()->owned()->for($zone, 'zone')->create(['name' => 'gone.example.com']);
    Http::fake(['api.cloudflare.com/*' => Http::response(['success' => false], 404)]);

    app(CloudflareDnsProvider::class)->deleteRecord($record);

    expect($record->fresh())->toBeNull()
        ->and(storageServiceAuditEvent('ui.dns_record.already_deleted')->metadata)->toMatchArray([
            'hostname' => 'gone.example.com',
            'provider' => 'cloudflare',
            'zone' => 'example.com',
        ]);
});
