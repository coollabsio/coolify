<?php

use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Models\InstanceSettings;
use App\Models\LocalPersistentVolume;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use App\Services\ServerTransfer\ServerTransferClaimer;
use App\Services\ServerTransfer\ServerTransferExporter;
use App\Services\ServerTransfer\ServerTransferImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('app.env', 'local');
    config()->set('app.maintenance.driver', 'file');
    Server::flushIdentityMap();
    Storage::fake('ssh-keys');
    Storage::fake('ssh-mux');
    Process::fake();
    InstanceSettings::forceCreate(['id' => 0, 'is_api_enabled' => true]);
    $this->mock(ServerTransferClaimer::class)->shouldReceive('claim')->andReturn([]);
});

/** @return array{application: Application, preview: ApplicationPreview, bundle: array} */
function previewOwnershipFixture(Team $team, bool $trashed = false): array
{
    $key = PrivateKey::factory()->create(['team_id' => $team->id]);
    $server = Server::factory()->create(['team_id' => $team->id, 'private_key_id' => $key->id]);
    $destination = StandaloneDocker::where('server_id', $server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $application = Application::factory()->create([
        'environment_id' => $project->environments()->firstOrFail()->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
    $preview = ApplicationPreview::create([
        'application_id' => $application->id,
        'pull_request_id' => 42,
        'pull_request_html_url' => 'https://github.com/example/app/pull/42',
        'fqdn' => 'https://preview.example.com',
    ]);
    $bundle = app(ServerTransferExporter::class)->export($server);
    $bundle['server']['uuid'] = new_public_id();
    $bundle['server']['ip'] = '192.0.2.99';
    if ($trashed) {
        $application->delete();
        $preview->delete();
    }

    return compact('application', 'preview', 'bundle');
}

test('preview imports reject another team UUID and roll back all imported records', function (bool $trashed, string $role) {
    $fixture = previewOwnershipFixture(Team::factory()->create(), $trashed);
    $preview = $fixture['preview'];
    $volume = LocalPersistentVolume::create([
        'resource_type' => $preview->getMorphClass(),
        'resource_id' => $preview->id,
        'name' => 'victim-preview-data',
        'mount_path' => '/data',
    ]);
    $bundle = $fixture['bundle'];
    $bundle['projects'][0]['environments'][0]['applications'][0]['previews'][0]['pull_request_id'] = 99;
    $bundle['projects'][0]['environments'][0]['applications'][0]['previews'][0]['fqdn'] = 'https://imported.example.com';
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->members()->attach($user->id, ['role' => $role]);
    session(['currentTeam' => $team]);
    $token = $user->createToken('preview-import', ['write']);
    $token->accessToken->forceFill(['team_id' => $team->id])->save();
    $counts = [Server::count(), Application::withTrashed()->count(), PrivateKey::count(), Project::count()];

    $this->withToken($token->plainTextToken)->postJson('/api/v1/servers/import', ['bundle' => $bundle])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('previews');

    expect($preview->fresh()->application_id)->toBe($fixture['application']->id)
        ->and($preview->fresh()->pull_request_id)->toBe(42)
        ->and($preview->fresh()->fqdn)->toBe('https://preview.example.com')
        ->and($preview->fresh()->trashed())->toBe($trashed);
    expect($volume->fresh()->resource_id)->toBe($preview->id);
    expect([Server::count(), Application::withTrashed()->count(), PrivateKey::count(), Project::count()])->toBe($counts);
})->with([
    'active preview, owner' => [false, 'owner'],
    'deleted preview and application, admin' => [true, 'admin'],
]);

test('preview imports reject another team domain without clearing it', function (bool $trashed, bool $preserveUuids) {
    $fixture = previewOwnershipFixture(Team::factory()->create(), $trashed);
    $bundle = $fixture['bundle'];
    $bundle['projects'][0]['environments'][0]['applications'][0]['previews'][0]['uuid'] = new_public_id();
    $team = Team::factory()->create();
    $serverCount = Server::count();

    try {
        app(ServerTransferImporter::class)->import($bundle, $team->id, preserveUuids: $preserveUuids);
        $this->fail('A foreign preview domain must be rejected.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('previews');
    }

    expect($fixture['preview']->fresh()->fqdn)->toBe('https://preview.example.com')
        ->and($fixture['preview']->fresh()->application_id)->toBe($fixture['application']->id)
        ->and($fixture['preview']->fresh()->trashed())->toBe($trashed);
    expect(Server::count())->toBe($serverCount);
    $this->assertDatabaseCount('application_previews', 1);
})->with([
    'active, preserve UUIDs' => [false, true],
    'deleted, replace UUIDs' => [true, false],
]);

test('preview imports preserve same-team UUID handoffs and preview volumes', function (bool $trashed) {
    $team = Team::factory()->create();
    $fixture = previewOwnershipFixture($team, $trashed);
    $preview = $fixture['preview'];
    $volume = LocalPersistentVolume::create([
        'resource_type' => $preview->getMorphClass(),
        'resource_id' => $preview->id,
        'name' => 'same-team-preview-data',
        'mount_path' => '/data',
    ]);

    $result = app(ServerTransferImporter::class)->import($fixture['bundle'], $team->id);

    $imported = Server::whereUuid($result['server_uuid'])->firstOrFail()->applications()->firstOrFail();
    expect($preview->fresh()->application_id)->toBe($imported->id)
        ->and($preview->fresh()->fqdn)->toBe('https://preview.example.com')
        ->and($preview->fresh()->trashed())->toBeFalse();
    expect($volume->fresh()->resource_id)->toBe($preview->id);
    $this->assertDatabaseCount('application_previews', 1);
})->with(['active' => false, 'deleted preview and application' => true]);

test('preview imports can release a same-team domain collision', function () {
    $team = Team::factory()->create();
    $fixture = previewOwnershipFixture($team);
    $bundle = $fixture['bundle'];
    $bundle['projects'][0]['environments'][0]['applications'][0]['previews'][0]['uuid'] = new_public_id();

    $result = app(ServerTransferImporter::class)->import($bundle, $team->id);

    $imported = Server::whereUuid($result['server_uuid'])->firstOrFail()->applications()->firstOrFail();
    expect($fixture['preview']->fresh()->fqdn)->toBeNull();
    expect($imported->previews()->firstOrFail()->fqdn)->toBe('https://preview.example.com');
});
