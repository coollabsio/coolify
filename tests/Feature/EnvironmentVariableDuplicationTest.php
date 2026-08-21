<?php

use App\Livewire\Project\Shared\EnvironmentVariable\Show;
use App\Models\Application;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);

    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->team->members()->attach($this->user, ['role' => 'owner']);
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
    $this->application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'build_pack' => 'dockerfile',
    ]);

    EnvironmentVariable::query()
        ->where('resourceable_type', Application::class)
        ->where('resourceable_id', $this->application->id)
        ->delete();

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

function createVariableForDuplicationTest(Application $application, array $overrides = []): EnvironmentVariable
{
    $environmentVariable = EnvironmentVariable::create(array_merge([
        'key' => 'API_KEY',
        'value' => 'secret-value',
        'order' => 1,
        'is_preview' => false,
        'resourceable_type' => Application::class,
        'resourceable_id' => $application->id,
    ], $overrides));

    // Creating production variables on applications auto-creates preview twins;
    // drop them so assertions stay predictable.
    if (! $environmentVariable->is_preview) {
        EnvironmentVariable::query()
            ->where('resourceable_type', Application::class)
            ->where('resourceable_id', $application->id)
            ->where('is_preview', true)
            ->delete();
    }

    return $environmentVariable->fresh();
}

it('duplicates a variable within the same resource with value and settings', function () {
    $environmentVariable = createVariableForDuplicationTest($this->application, [
        'key' => 'API_KEY',
        'value' => 'super-secret',
        'is_literal' => true,
        'is_buildtime' => false,
        'is_required' => true,
        'comment' => 'primary credential',
    ]);

    Livewire::test(Show::class, ['env' => $environmentVariable, 'type' => 'application'])
        ->call('prepareDuplicate')
        ->assertSet('duplicateKey', 'API_KEY_COPY')
        ->call('duplicateVariable', 'application:'.$this->application->id)
        ->assertDispatched('success')
        ->assertSet('duplicateModalOpen', false);

    $duplicate = EnvironmentVariable::query()
        ->where('resourceable_type', Application::class)
        ->where('resourceable_id', $this->application->id)
        ->where('is_preview', false)
        ->where('key', 'API_KEY_COPY')
        ->first();

    expect($duplicate)->not->toBeNull()
        ->and($duplicate->value)->toBe('super-secret')
        ->and((bool) $duplicate->is_literal)->toBeTrue()
        ->and($duplicate->is_buildtime)->toBeFalse()
        ->and($duplicate->comment)->toBe('primary credential')
        ->and((bool) $duplicate->is_required)->toBeFalse()
        ->and($duplicate->uuid)->not->toBe($environmentVariable->uuid)
        ->and((int) $duplicate->order)->toBe(2);
});

it('suggests a numbered name when the default copy name is taken', function () {
    $environmentVariable = createVariableForDuplicationTest($this->application, ['key' => 'API_KEY']);
    createVariableForDuplicationTest($this->application, ['key' => 'API_KEY_COPY', 'order' => 2]);

    Livewire::test(Show::class, ['env' => $environmentVariable, 'type' => 'application'])
        ->call('prepareDuplicate')
        ->assertSet('duplicateKey', 'API_KEY_COPY2');
});

it('rejects a duplicate when the name already exists on the target resource', function () {
    $environmentVariable = createVariableForDuplicationTest($this->application, ['key' => 'API_KEY']);
    createVariableForDuplicationTest($this->application, ['key' => 'API_KEY_TAKEN', 'order' => 2]);

    Livewire::test(Show::class, ['env' => $environmentVariable, 'type' => 'application'])
        ->set('duplicateKey', 'API_KEY_TAKEN')
        ->call('duplicateVariable', 'application:'.$this->application->id)
        ->assertDispatched('error');

    expect(EnvironmentVariable::query()
        ->where('resourceable_type', Application::class)
        ->where('resourceable_id', $this->application->id)
        ->where('is_preview', false)
        ->count())->toBe(2);
});

it('rejects invalid or reserved names', function () {
    $environmentVariable = createVariableForDuplicationTest($this->application, ['key' => 'API_KEY']);

    $component = Livewire::test(Show::class, ['env' => $environmentVariable, 'type' => 'application']);

    $component->set('duplicateKey', '1INVALID KEY')
        ->call('duplicateVariable', 'application:'.$this->application->id)
        ->assertDispatched('error');

    $component->set('duplicateKey', 'SERVICE_FQDN_APP')
        ->call('duplicateVariable', 'application:'.$this->application->id)
        ->assertDispatched('error');

    expect(EnvironmentVariable::query()
        ->where('resourceable_type', Application::class)
        ->where('resourceable_id', $this->application->id)
        ->where('is_preview', false)
        ->count())->toBe(1);
});

it('copies a variable to a resource in another project', function () {
    $environmentVariable = createVariableForDuplicationTest($this->application, [
        'key' => 'SHARED_TOKEN',
        'value' => 'copy-me',
    ]);

    $otherProject = Project::factory()->create(['team_id' => $this->team->id]);
    $otherEnvironment = Environment::factory()->create(['project_id' => $otherProject->id]);
    $otherApplication = Application::factory()->create([
        'environment_id' => $otherEnvironment->id,
        'build_pack' => 'dockerfile',
    ]);
    EnvironmentVariable::query()
        ->where('resourceable_type', Application::class)
        ->where('resourceable_id', $otherApplication->id)
        ->delete();

    Livewire::test(Show::class, ['env' => $environmentVariable, 'type' => 'application'])
        ->set('duplicateKey', 'SHARED_TOKEN')
        ->call('duplicateVariable', 'application:'.$otherApplication->id)
        ->assertDispatched('success');

    $copy = EnvironmentVariable::query()
        ->where('resourceable_type', Application::class)
        ->where('resourceable_id', $otherApplication->id)
        ->where('is_preview', false)
        ->where('key', 'SHARED_TOKEN')
        ->first();

    expect($copy)->not->toBeNull()
        ->and($copy->value)->toBe('copy-me')
        ->and((int) $copy->order)->toBe(1);
});

it('copies preview variables to non-application resources as production variables', function () {
    $environmentVariable = createVariableForDuplicationTest($this->application, [
        'key' => 'PREVIEW_TOKEN',
        'value' => 'preview-secret',
        'is_preview' => true,
    ]);

    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $destination = StandaloneDocker::where('server_id', $server->id)->firstOrFail();
    $database = StandalonePostgresql::create([
        'name' => 'postgres',
        'image' => 'postgres:16-alpine',
        'postgres_user' => 'postgres',
        'postgres_password' => 'password',
        'postgres_db' => 'postgres',
        'environment_id' => $this->environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);

    Livewire::test(Show::class, ['env' => $environmentVariable, 'type' => 'application'])
        ->set('duplicateKey', 'PREVIEW_TOKEN')
        ->call('duplicateVariable', 'standalone-postgresql:'.$database->id)
        ->assertDispatched('success');

    $copy = EnvironmentVariable::query()
        ->where('resourceable_type', StandalonePostgresql::class)
        ->where('resourceable_id', $database->id)
        ->where('key', 'PREVIEW_TOKEN')
        ->first();

    expect($copy)->not->toBeNull()
        ->and((bool) $copy->is_preview)->toBeFalse()
        ->and($copy->value)->toBe('preview-secret');
});

it('rejects targets that belong to another team', function () {
    $environmentVariable = createVariableForDuplicationTest($this->application, ['key' => 'API_KEY']);

    $otherTeam = Team::factory()->create();
    $otherProject = Project::factory()->create(['team_id' => $otherTeam->id]);
    $otherEnvironment = Environment::factory()->create(['project_id' => $otherProject->id]);
    $otherApplication = Application::factory()->create([
        'environment_id' => $otherEnvironment->id,
        'build_pack' => 'dockerfile',
    ]);
    EnvironmentVariable::query()
        ->where('resourceable_type', Application::class)
        ->where('resourceable_id', $otherApplication->id)
        ->delete();

    Livewire::test(Show::class, ['env' => $environmentVariable, 'type' => 'application'])
        ->set('duplicateKey', 'API_KEY')
        ->call('duplicateVariable', 'application:'.$otherApplication->id)
        ->assertDispatched('error');

    expect(EnvironmentVariable::query()
        ->where('resourceable_type', Application::class)
        ->where('resourceable_id', $otherApplication->id)
        ->count())->toBe(0);
});

it('does not allow members to duplicate variables', function () {
    $environmentVariable = createVariableForDuplicationTest($this->application, ['key' => 'API_KEY']);

    $member = User::factory()->create();
    $this->team->members()->attach($member, ['role' => 'member']);
    $this->actingAs($member);
    session(['currentTeam' => $this->team]);

    Livewire::test(Show::class, ['env' => $environmentVariable, 'type' => 'application'])
        ->set('duplicateKey', 'API_KEY_COPY')
        ->call('duplicateVariable', 'application:'.$this->application->id);

    expect(EnvironmentVariable::query()
        ->where('resourceable_type', Application::class)
        ->where('resourceable_id', $this->application->id)
        ->where('is_preview', false)
        ->count())->toBe(1);
});
