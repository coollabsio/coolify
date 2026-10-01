<?php

use App\Livewire\Server\Transfer;
use App\Livewire\Server\TransferImport;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\ServerTransfer\ServerTransferClaimer;
use App\Services\ServerTransfer\ServerTransferExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['app.env' => 'local']);

    InstanceSettings::forceCreate(['id' => 0, 'is_api_enabled' => true, 'fqdn' => 'https://coolify-a.test']);

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
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

test('server transfer pages are unavailable outside development mode', function (string $uri) {
    config(['app.env' => 'production']);

    $this->get($uri)->assertNotFound();
})->with([
    '/servers/import',
    fn () => '/server/'.$this->server->uuid.'/transfer',
]);

test('server transfer links are hidden outside development mode', function () {
    config(['app.env' => 'production']);

    $this->get('/servers')
        ->assertOk()
        ->assertDontSee('/servers/import');

    $this->get('/server/'.$this->server->uuid)
        ->assertOk()
        ->assertDontSee('Transfer', escape: false);
});

test('transfer state is hidden outside development mode', function (bool $managementDisabled) {
    app(ServerTransferClaimer::class)->markTransferred($this->server, managementDisabled: $managementDisabled);
    config(['app.env' => 'production']);

    $this->get('/servers')
        ->assertOk()
        ->assertDontSee('Transferable')
        ->assertDontSee('Transferred away');

    $this->get('/server/'.$this->server->uuid)
        ->assertOk()
        ->assertDontSee('Transfer', escape: false)
        ->assertDontSee('/transfer"', escape: false)
        ->assertDontSee('another instance')
        ->assertDontSee('Enable management')
        ->assertDontSee('Disable management');
})->with([
    'management disabled' => [true],
    'transferred away' => [false],
]);

test('transfer page renders for owned server', function () {
    Livewire::test(Transfer::class, ['server_uuid' => $this->server->uuid])
        ->assertOk()
        ->assertSee('Transfer server')
        ->assertSee('Target instance URL')
        ->assertSee('Target API token')
        ->assertSee('Transfer server')
        ->assertSee('Manual transfer');
});

test('transfer import page renders for admin', function () {
    Livewire::test(TransferImport::class)
        ->assertOk()
        ->assertSee('Transfer file')
        ->assertSee('Dry run')
        ->assertSee('Import server');
});

test('export bundle streams download from livewire', function () {
    Livewire::test(Transfer::class, ['server_uuid' => $this->server->uuid])
        ->call('exportBundle')
        ->assertFileDownloaded('server-transfer-'.$this->server->uuid.'.json');
});

test('transfer page badge shows the management state of the server', function (string $state, string $label, array $hidden) {
    $claimer = app(ServerTransferClaimer::class);
    match ($state) {
        'managed' => null,
        'disabled' => $claimer->markTransferred($this->server, managementDisabled: true),
        'transferred' => $claimer->markTransferred($this->server, targetInstanceUrl: 'https://coolify-b.test'),
    };

    Livewire::test(Transfer::class, ['server_uuid' => $this->server->fresh()->uuid])
        ->assertSee($label)
        ->assertDontSee($hidden);
})->with([
    'managed' => ['managed', 'Managed here', ['Transferable', 'Transferred away']],
    'management disabled' => ['disabled', 'Transferable', ['Transferred away', 'Managed here']],
    'transferred' => ['transferred', 'Transferred away', ['Transferable', 'Managed here']],
]);

test('export bundle is encrypted only when a passphrase is set', function (?string $passphrase, string $fileName) {
    Livewire::test(Transfer::class, ['server_uuid' => $this->server->uuid])
        ->set('passphrase', $passphrase)
        ->call('exportBundle')
        ->assertFileDownloaded('server-transfer-'.$this->server->uuid.$fileName);
})->with([
    'null' => [null, '.json'],
    'empty string' => ['', '.json'],
    'only spaces' => ['   ', '.json'],
    'set' => ['secret-pass', '.encrypted.json'],
]);

test('import dry run from pasted json', function () {
    $bundle = app(ServerTransferExporter::class)->export($this->server);

    // Free IP for dry-run validation on same team would still report existing server
    Livewire::test(TransferImport::class)
        ->set('bundleJson', json_encode($bundle))
        ->call('dryRun')
        ->assertSet('lastResult.dry_run', true)
        ->assertSet('lastResult.server_uuid', $this->server->uuid)
        ->assertSee('Dry run result')
        ->assertSee('Nothing written');
});

test('import creates server after source removal', function () {
    $bundle = app(ServerTransferExporter::class)->export($this->server);
    $uuid = $this->server->uuid;

    $this->server->forceDelete();
    $this->privateKey->delete();

    Livewire::test(TransferImport::class)
        ->set('bundleJson', json_encode($bundle))
        ->set('preserveUuids', true)
        ->set('adoptMode', true)
        ->call('importBundle')
        ->assertSet('importedServerUuid', $uuid)
        ->assertSet('lastResult.dry_run', false)
        ->assertSet('lastResult.claimed', true);

    $server = Server::where('uuid', $uuid)->first();
    expect($server)->not->toBeNull()
        ->and(data_get($server->server_metadata, 'transfer.status'))->toBe('claimed');
});
