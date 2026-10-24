<?php

use App\Actions\Server\DeleteServer;
use App\Jobs\DeleteResourceJob;
use App\Jobs\MigrateServerToInstanceJob;
use App\Jobs\ReleaseManagedDnsRecordsJob;
use App\Jobs\ValidateAndInstallServerJob;
use App\Livewire\Server\Delete;
use App\Livewire\Server\Transfer;
use App\Models\Application;
use App\Models\DnsProviderZone;
use App\Models\InstanceSettings;
use App\Models\IntegrationToken;
use App\Models\ManagedDnsRecord;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use App\Services\ServerTransfer\ServerTransferClaimer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    // A claim writes the owner marker on the host over SSH.
    Process::fake();
    Storage::fake('ssh-keys');
    // An import validates the claimed server over SSH; the sync test queue would run it.
    Queue::fake([ValidateAndInstallServerJob::class]);
    config(['app.env' => 'production']);
    $this->withoutVite();

    InstanceSettings::forceCreate(['id' => 0, 'is_api_enabled' => true, 'fqdn' => 'https://coolify-a.test']);

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    // The default password of UserFactory.
    $this->userPassword = 'password'; // ggignore
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $this->privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);
    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $this->privateKey->id,
        'ip' => '10.77.0.10',
        'name' => 'ui-transfer-server',
    ]);
});

test('server transfer page is available in production', function () {
    $this->get('/server/'.$this->server->uuid.'/transfer')->assertOk();
});

test('server transfer link is shown to admins in production', function () {
    $this->get('/server/'.$this->server->uuid)
        ->assertOk()
        ->assertSee('/server/'.$this->server->uuid.'/transfer', escape: false);
});

test('taking back a transferred server needs a typed confirmation', function () {
    app(ServerTransferClaimer::class)->markTransferred($this->server, targetInstanceUrl: 'https://target.example.com');

    $this->get('/server/'.$this->server->uuid)
        ->assertOk()
        ->assertSee('Take back a transferred server?')
        ->assertSee('Changes made on https://target.example.com after', escape: false);
});

test('team members cannot open the transfer page', function () {
    $member = User::factory()->create();
    $this->team->members()->attach($member->id, ['role' => 'member']);
    $this->actingAs($member);

    Livewire::test(Transfer::class, ['server_uuid' => $this->server->uuid])
        ->assertRedirect(route('server.index'));

    $this->get('/server/'.$this->server->uuid)
        ->assertOk()
        ->assertDontSee('/server/'.$this->server->uuid.'/transfer', escape: false);
});

test('a member who owns another team cannot use that role to transfer the server', function () {
    $member = User::factory()->create();
    $this->team->members()->attach($member->id, ['role' => 'member']);
    $otherTeam = Team::factory()->create();
    $otherTeam->members()->attach($member->id, ['role' => 'owner']);
    $this->actingAs($member);

    session(['currentTeam' => $this->team]);
    Livewire::test(Transfer::class, ['server_uuid' => $this->server->uuid])
        ->assertRedirect(route('server.index'));

    session(['currentTeam' => $otherTeam]);
    Livewire::test(Transfer::class, ['server_uuid' => $this->server->uuid])
        ->assertStatus(404);
});

test('transfer page renders for owned server', function () {
    Livewire::test(Transfer::class, ['server_uuid' => $this->server->uuid])
        ->assertOk()
        ->assertSee('Transfer server')
        ->assertSee('Target instance URL')
        ->assertSee('Target API token')
        ->assertDontSee('Manual transfer')
        ->assertDontSee('Passphrase');
});

test('transfer page badge shows the management state of the server', function (string $state, string $label, array $hidden) {
    $claimer = app(ServerTransferClaimer::class);
    match ($state) {
        'managed' => null,
        'transferred' => $claimer->markTransferred($this->server, targetInstanceUrl: 'https://coolify-b.test'),
    };

    Livewire::test(Transfer::class, ['server_uuid' => $this->server->fresh()->uuid])
        ->assertSee($label)
        ->assertDontSee($hidden);
})->with([
    'managed' => ['managed', 'Managed here', ['Transferred away']],
    'transferred' => ['transferred', 'Transferred away', ['Managed here']],
]);

test('transfer to another instance runs in a queued job', function () {
    Queue::fake();
    Http::fake(['*/api/v1/servers/import/check*' => Http::response(['message' => 'ok', 'checks' => []])]);

    Livewire::test(Transfer::class, ['server_uuid' => $this->server->uuid])
        ->set('targetUrl', 'https://8.8.8.8')
        ->set('targetToken', 'target-token')
        ->call('migrateServer')
        ->assertHasNoErrors()
        ->assertSet('targetToken', '')
        ->assertSee('Transfer in progress');

    Queue::assertPushed(MigrateServerToInstanceJob::class);
    expect(data_get($this->server->fresh()->server_metadata, 'migration.status'))->toBe('running');
});

test('a team admin cannot start a transfer, because it sends every secret to the target', function () {
    Queue::fake();
    Http::fake(['*/api/v1/servers/import/check*' => Http::response(['message' => 'ok', 'checks' => []])]);
    $admin = User::factory()->create();
    $this->team->members()->attach($admin->id, ['role' => 'admin']);
    $this->actingAs($admin);

    Livewire::test(Transfer::class, ['server_uuid' => $this->server->uuid])
        ->set('targetUrl', 'https://8.8.8.8')
        ->set('targetToken', 'target-token')
        ->call('migrateServer')
        ->assertSet('targetToken', '');

    Queue::assertNotPushed(MigrateServerToInstanceJob::class);
    expect(data_get($this->server->fresh()->server_metadata, 'migration'))->toBeNull();
});

test('the transfer confirmation says that locked secrets are sent to the target', function () {
    Livewire::test(Transfer::class, ['server_uuid' => $this->server->uuid])
        ->assertSee('every environment variable value, also locked ones');
});

test('transfer page shows the error of a failed transfer', function () {
    $this->server->forceFill(['server_metadata' => ['migration' => [
        'status' => 'failed',
        'target_url' => 'https://8.8.8.8',
        'error' => 'Target import failed (HTTP 401): Unauthenticated.',
    ]]])->save();

    Livewire::test(Transfer::class, ['server_uuid' => $this->server->uuid])
        ->assertSee('Transfer failed')
        ->assertSee('Target import failed (HTTP 401): Unauthenticated.');
});

test('a transferred server cannot delete its cloud provider machine', function () {
    Queue::fake();
    $this->server->forceFill(['hetzner_server_id' => 4242])->save();
    app(ServerTransferClaimer::class)->markTransferred($this->server, targetInstanceUrl: 'https://coolify-b.test');

    $component = Livewire::test(Delete::class, ['server_uuid' => $this->server->uuid]);
    expect(collect($component->viewData('checkboxes'))->pluck('id'))->not->toContain('delete_from_hetzner');

    $component->call('delete', $this->userPassword, ['delete_from_hetzner']);

    DeleteServer::assertPushed(fn ($action, array $parameters) => $parameters[1] === false && $parameters[2] === 4242);
});

test('a transferred server does not show Hostinger VPS deletion guidance', function () {
    $this->server->forceFill(['hostinger_virtual_machine_id' => 4242])->save();
    app(ServerTransferClaimer::class)->markTransferred($this->server, targetInstanceUrl: 'https://coolify-b.test');

    Livewire::test(Delete::class, ['server_uuid' => $this->server->uuid])
        ->assertDontSee('Hostinger deletes')
        ->assertDontSee('hpanel.hostinger.com');
});

test('a managed server can still delete its cloud provider machine', function () {
    $this->server->forceFill(['hetzner_server_id' => 4242])->save();

    $component = Livewire::test(Delete::class, ['server_uuid' => $this->server->uuid]);

    expect(collect($component->viewData('checkboxes'))->pluck('id'))->toContain('delete_from_hetzner');
});

test('deleting a transferred server deletes its resources from this instance only', function () {
    Queue::fake();
    $destination = StandaloneDocker::where('server_id', $this->server->id)->firstOrFail();
    $application = Application::factory()->create([
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
    app(ServerTransferClaimer::class)->markTransferred($this->server, targetInstanceUrl: 'https://coolify-b.test');

    $component = Livewire::test(Delete::class, ['server_uuid' => $this->server->uuid]);
    expect(collect($component->viewData('checkboxes'))->pluck('id'))->not->toContain('force_delete_resources');

    $component->call('delete', $this->userPassword);

    Queue::assertPushed(DeleteResourceJob::class, fn (DeleteResourceJob $job) => $job->resource->is($application) && $job->deleteFromCoolifyOnly);
    expect(Server::find($this->server->id))->toBeNull();
});

test('deleting a transferred server forgets the DNS records of its resources without releasing them at the provider', function () {
    Queue::fake();
    $destination = StandaloneDocker::where('server_id', $this->server->id)->firstOrFail();
    $application = Application::factory()->create(['destination_id' => $destination->id, 'destination_type' => $destination->getMorphClass()]);
    // An archive that still tracks a record, for example from a transfer before records were forgotten at claim time.
    $this->server->forceFill(['server_metadata' => ['transfer' => ['status' => 'transferred']]])->save();
    $token = IntegrationToken::factory()->for($this->team)->create(['provider' => 'cloudflare']);
    $zone = DnsProviderZone::factory()->create(['integration_token_id' => $token->id]);
    $record = ManagedDnsRecord::factory()->owned()->create(['dns_provider_zone_id' => $zone->id]);
    $record->addReference($application);

    Livewire::test(Delete::class, ['server_uuid' => $this->server->uuid])->call('delete', $this->userPassword);
    (new DeleteResourceJob($application->fresh(), deleteFromCoolifyOnly: true))->handle();

    Queue::assertNotPushed(ReleaseManagedDnsRecordsJob::class);
    expect(ManagedDnsRecord::find($record->id))->toBeNull()
        ->and(Application::withTrashed()->find($application->id))->toBeNull();
});

test('deleting an application from Coolify only does not touch the server', function () {
    $destination = StandaloneDocker::where('server_id', $this->server->id)->firstOrFail();
    $application = Application::factory()->create([
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
    Process::fake();

    (new DeleteResourceJob($application, deleteFromCoolifyOnly: true))->handle();

    Process::assertNothingRan();
    expect(Application::withTrashed()->find($application->id))->toBeNull();
});

test('transfer with a token that the target does not accept shows an error and does not start', function () {
    Queue::fake();
    Http::fake(['*/api/v1/servers/import/check*' => Http::response(['message' => 'Unauthenticated.'], 401)]);

    Livewire::test(Transfer::class, ['server_uuid' => $this->server->uuid])
        ->set('targetUrl', 'https://8.8.8.8')
        ->set('targetToken', 'wrong-token')
        ->call('migrateServer')
        ->assertDispatched('error')
        ->assertDontSee('Transfer in progress')
        ->assertSee('A check failed. The transfer did not start.')
        ->assertSee('OK: this instance can connect to the server over SSH.')
        ->assertSee('FAILED: the target instance accepts the API token.')
        ->assertSee('See the transfer log below for details.')
        ->assertDontSee('checks failed:');

    Queue::assertNotPushed(MigrateServerToInstanceJob::class);
});

test('transfer page links to the server on the target and lists the steps after a finished transfer', function () {
    $this->server->forceFill(['server_metadata' => ['migration' => [
        'status' => 'succeeded',
        'target_url' => 'https://8.8.8.8',
        'checklist' => ['Set the webhook URL of GitHub App <b>deploy-bot</b> to https://8.8.8.8/webhooks/source/github/events.'],
        'target_server_url' => 'https://8.8.8.8/server/abc',
    ]]])->save();

    Livewire::test(Transfer::class, ['server_uuid' => $this->server->uuid])
        ->assertSee('Transfer finished')
        ->assertSee('Finish the transfer on the target')
        ->assertSee('href="https://8.8.8.8/server/abc"', escape: false)
        ->assertSee('Set the webhook URL of GitHub App <b>deploy-bot</b> to https://8.8.8.8/webhooks/source/github/events.')
        ->assertDontSee('<b>deploy-bot</b>', escape: false);
});

test('transfer page hides the post-transfer checklist', function (string $status, array $checklist) {
    $this->server->forceFill(['server_metadata' => ['migration' => [
        'status' => $status,
        'target_url' => 'https://8.8.8.8',
        'error' => 'Target import failed (HTTP 401): Unauthenticated.',
        'checklist' => $checklist,
    ]]])->save();

    Livewire::test(Transfer::class, ['server_uuid' => $this->server->uuid])
        ->assertDontSee('Finish the transfer on the target')
        ->assertDontSee('Set the webhook URL');
})->with([
    'finished transfer without steps' => ['succeeded', []],
    'failed transfer' => ['failed', ['Set the webhook URL of GitHub App deploy-bot.']],
]);

test('the transfer page of an archive offers a transfer back instead of a transfer', function () {
    app(ServerTransferClaimer::class)->markTransferred($this->server, targetInstanceUrl: 'https://8.8.8.8');
    Http::fake(['https://8.8.8.8/api/v1/servers/*/migrate' => Http::response(['migration' => ['status' => 'running']], 202)]);

    Livewire::test(Transfer::class, ['server_uuid' => $this->server->uuid])
        ->assertSet('managerUrl', 'https://8.8.8.8')
        ->assertDontSee('Transfer to another instance')
        ->set('managerToken', 'token-of-b')
        ->call('transferBack')
        ->assertDispatched('success')
        ->assertSee('Transfer back started');

    Http::assertSentCount(1);
});

test('team members cannot start a transfer back', function () {
    app(ServerTransferClaimer::class)->markTransferred($this->server, targetInstanceUrl: 'https://8.8.8.8');
    Http::fake();
    $component = Livewire::test(Transfer::class, ['server_uuid' => $this->server->uuid]);
    $member = User::factory()->create();
    $this->team->members()->attach($member->id, ['role' => 'member']);
    $this->actingAs($member);

    $component->set('managerToken', 'token-of-b')->call('transferBack');

    Http::assertNothingSent();
});

test('the transfer log cannot be polled after the user lost the admin role', function () {
    $component = Livewire::test(Transfer::class, ['server_uuid' => $this->server->uuid]);
    $member = User::factory()->create();
    $this->team->members()->attach($member->id, ['role' => 'member']);
    $this->actingAs($member);

    $component->call('refreshMigration')->assertForbidden();
});

test('the tokens are not kept in the component after a failed action', function () {
    Queue::fake();
    Http::fake(['*' => Http::response(['message' => 'Unauthenticated.'], 401)]);

    Livewire::test(Transfer::class, ['server_uuid' => $this->server->uuid])
        ->set('targetUrl', 'https://8.8.8.8')
        ->set('targetToken', 'secret-target-token')
        ->call('migrateServer')
        ->assertDispatched('error')
        ->assertSet('targetToken', '');

    app(ServerTransferClaimer::class)->markTransferred($this->server, targetInstanceUrl: 'https://8.8.8.8');
    Livewire::test(Transfer::class, ['server_uuid' => $this->server->uuid])
        ->set('managerToken', 'secret-manager-token')
        ->call('transferBack')
        ->assertDispatched('error')
        ->assertSet('managerToken', '');
});

test('the URL of an owner marker is not prefilled for a transfer back', function () {
    $this->server->forceFill(['server_metadata' => ['transfer' => ['status' => 'claimed', 'claim' => ['claim_id' => 'own', 'claimed_at' => now()->subDay()->toIso8601String()]]]])->save();
    app(ServerTransferClaimer::class)->releaseIfClaimedElsewhere($this->server->fresh(), json_encode([
        'claim_id' => 'other', 'instance_url' => 'https://8.8.8.8', 'claimed_at' => now()->toIso8601String(),
    ]));
    expect($this->server->fresh()->isTransferredAway())->toBeTrue();

    Livewire::test(Transfer::class, ['server_uuid' => $this->server->uuid])
        ->assertSet('managerUrl', '')
        ->assertSee('data-testid="transfer-back-host"', escape: false);
});

test('a stored target URL that is not a safe instance URL is not prefilled for a transfer back', function () {
    app(ServerTransferClaimer::class)->markTransferred($this->server, targetInstanceUrl: 'http://10.0.0.5:8000');

    Livewire::test(Transfer::class, ['server_uuid' => $this->server->uuid])
        ->assertSet('managerUrl', '');
});

test('the transfer page does not link to a server URL that is not https', function () {
    $this->server->forceFill(['server_metadata' => ['migration' => [
        'status' => 'succeeded',
        'target_url' => 'https://8.8.8.8',
        'checklist' => ['A step.'],
        'target_server_url' => 'javascript:alert(document.cookie)',
    ]]])->save();

    Livewire::test(Transfer::class, ['server_uuid' => $this->server->uuid])
        ->assertSee('Finish the transfer on the target')
        ->assertDontSee('javascript:alert', escape: false);
});

test('the transfer page warns about an untrusted owner marker', function () {
    $this->server->forceFill(['server_metadata' => ['transfer' => ['status' => 'claimed', 'claim' => ['claim_id' => 'own', 'claimed_at' => now()->toIso8601String()]]]])->save();
    app(ServerTransferClaimer::class)->releaseIfClaimedElsewhere($this->server->fresh(), json_encode([
        'claim_id' => 'forged', 'instance_url' => 'https://evil.example', 'claimed_at' => now()->subDay()->toIso8601String(),
    ]));

    Livewire::test(Transfer::class, ['server_uuid' => $this->server->uuid])
        ->assertSee('Another instance may claim this server')
        ->assertSee('https://evil.example');
});

test('deleting an archive keeps applications that use the server only as an additional destination', function () {
    Queue::fake();
    $otherServer = Server::factory()->create(['team_id' => $this->team->id, 'private_key_id' => $this->privateKey->id, 'ip' => '10.77.0.11']);
    $otherDestination = StandaloneDocker::where('server_id', $otherServer->id)->firstOrFail();
    $destination = StandaloneDocker::where('server_id', $this->server->id)->firstOrFail();
    $sharedApplication = Application::factory()->create(['destination_id' => $otherDestination->id, 'destination_type' => $otherDestination->getMorphClass()]);
    $sharedApplication->additional_servers()->attach($this->server->id, ['standalone_docker_id' => $destination->id]);
    $application = Application::factory()->create(['destination_id' => $destination->id, 'destination_type' => $destination->getMorphClass()]);
    app(ServerTransferClaimer::class)->markTransferred($this->server, targetInstanceUrl: 'https://coolify-b.test');

    Livewire::test(Delete::class, ['server_uuid' => $this->server->uuid])->call('delete', $this->userPassword);

    Queue::assertPushed(DeleteResourceJob::class, fn (DeleteResourceJob $job) => $job->resource->is($application));
    Queue::assertNotPushed(DeleteResourceJob::class, fn (DeleteResourceJob $job) => $job->resource->is($sharedApplication));
    expect($sharedApplication->additional_servers()->count())->toBe(0);
});
