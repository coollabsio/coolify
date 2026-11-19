<?php

use App\Livewire\Destination\Show as DestinationShow;
use App\Livewire\Project\Database\Clickhouse\General as ClickhouseGeneral;
use App\Livewire\Project\Database\Dragonfly\General as DragonflyGeneral;
use App\Livewire\Project\Database\Keydb\General as KeydbGeneral;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);

    $this->team = Team::factory()->create();
    $this->owner = User::factory()->create();
    $this->team->members()->attach($this->owner->id, ['role' => 'owner']);

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::where('server_id', $this->server->id)->first();
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $project->id]);

    $this->actingAs($this->owner);
    session(['currentTeam' => $this->team]);
});

dataset('read-only-credential-components', [
    'keydb' => ['create_standalone_keydb', KeydbGeneral::class, ['keydbPassword' => 'keydb_password']],
    'dragonfly' => ['create_standalone_dragonfly', DragonflyGeneral::class, ['dragonflyPassword' => 'dragonfly_password']],
    'clickhouse' => ['create_standalone_clickhouse', ClickhouseGeneral::class, [
        'clickhouseAdminUser' => 'clickhouse_admin_user',
        'clickhouseAdminPassword' => 'clickhouse_admin_password',
    ]],
]);

it('does not save read-only credentials of a started database', function (string $createDatabase, string $generalClass, array $fields) {
    $database = $createDatabase($this->environment->id, $this->destination, ['started_at' => now()])->fresh();
    $stored = collect($fields)->mapWithKeys(fn (string $column) => [$column => $database->{$column}]);

    $component = Livewire::test($generalClass, ['database' => $database])->set('description', 'Updated');
    foreach (array_keys($fields) as $property) {
        $component->set($property, 'AutofilledLogin1234567890');
    }
    $component->call('submit')->assertHasNoErrors();

    $database->refresh();
    expect($database->description)->toBe('Updated');
    foreach ($stored as $column => $value) {
        expect($database->{$column})->toBe($value);
    }
})->with('read-only-credential-components');

it('saves credentials of a database that was never started', function (string $createDatabase, string $generalClass, array $fields) {
    $database = $createDatabase($this->environment->id, $this->destination)->fresh();

    $component = Livewire::test($generalClass, ['database' => $database]);
    foreach (array_keys($fields) as $property) {
        $component->set($property, 'ChangedBeforeStart1234567890');
    }
    $component->call('submit')->assertHasNoErrors();

    $database->refresh();
    foreach ($fields as $column) {
        expect($database->{$column})->toBe('ChangedBeforeStart1234567890');
    }
})->with('read-only-credential-components');

it('rejects changes to the read-only destination network and server IP', function (string $property) {
    $component = Livewire::test(DestinationShow::class, ['destination_uuid' => $this->destination->uuid]);

    expect(fn () => $component->set($property, 'tampered'))->toThrow(CannotUpdateLockedPropertyException::class);
})->with(['network', 'serverIp']);

it('saves the destination name without changing the network or server IP', function () {
    $network = $this->destination->network;
    $ip = (string) $this->server->ip;

    Livewire::test(DestinationShow::class, ['destination_uuid' => $this->destination->uuid])
        ->set('name', 'renamed-destination')
        ->call('submit')
        ->assertHasNoErrors();

    expect($this->destination->fresh()->name)->toBe('renamed-destination')
        ->and($this->destination->fresh()->network)->toBe($network)
        ->and((string) $this->server->fresh()->ip)->toBe($ip);
});
