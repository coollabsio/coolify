<?php

use App\Livewire\Project\Shared\EnvironmentVariable\All;
use App\Livewire\Project\Shared\EnvironmentVariable\Show;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);

    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->team->members()->attach($this->user, ['role' => 'owner']);
    $this->project = Project::factory()->create([
        'team_id' => $this->team->id,
    ]);
    $this->environment = Environment::factory()->create([
        'project_id' => $this->project->id,
    ]);
    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::where('server_id', $this->server->id)->first();
    $this->service = Service::factory()->create([
        'server_id' => $this->server->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'environment_id' => $this->environment->id,
        'docker_compose' => <<<'YAML'
services:
  app:
    image: nginx
    environment:
      JOB_ATTEMPTS: '3'
YAML,
    ]);

    $this->actingAs($this->user);
});

function createServiceEnvironmentVariable(Service $service, string $key, string $value): EnvironmentVariable
{
    return EnvironmentVariable::create([
        'key' => $key,
        'value' => $value,
        'resourceable_type' => Service::class,
        'resourceable_id' => $service->id,
        'is_preview' => false,
    ]);
}

/**
 * Mimics legacy duplicate rows that existed before the unique constraint
 * by temporarily dropping the index, seeding, and restoring it afterwards.
 */
function seedLegacyDuplicates(Service $service, string $key, int $count): array
{
    Schema::table('environment_variables', function ($table) {
        $table->dropUnique('env_vars_resourceable_key_preview_unique');
    });

    $rows = [];
    for ($i = 1; $i <= $count; $i++) {
        $rows[] = createServiceEnvironmentVariable($service, $key, "value-{$i}");
    }

    return $rows;
}

it('allows deleting a duplicate row of a Docker-Compose-required key via normal view', function () {
    [$first, $second] = seedLegacyDuplicates($this->service, 'JOB_ATTEMPTS', 2);

    Livewire::test(Show::class, ['env' => $second->fresh(), 'type' => 'service'])
        ->call('delete')
        ->assertDispatched('success');

    $remaining = EnvironmentVariable::where('resourceable_type', Service::class)
        ->where('resourceable_id', $this->service->id)
        ->where('key', 'JOB_ATTEMPTS')
        ->get();

    expect($remaining)->toHaveCount(1);
    expect($remaining->first()->id)->toBe($first->id);
});

it('still blocks deleting the last remaining row of a Docker-Compose-required key', function () {
    $env = createServiceEnvironmentVariable($this->service, 'JOB_ATTEMPTS', '3');

    Livewire::test(Show::class, ['env' => $env->fresh(), 'type' => 'service'])
        ->call('delete')
        ->assertDispatched('error');

    expect(EnvironmentVariable::where('resourceable_type', Service::class)
        ->where('resourceable_id', $this->service->id)
        ->where('key', 'JOB_ATTEMPTS')
        ->count())->toBe(1);
});

it('removes surplus duplicate rows of kept keys on bulk save', function () {
    seedLegacyDuplicates($this->service, 'JOB_ATTEMPTS', 3);

    Livewire::test(All::class, ['resource' => $this->service])
        ->set('variables', 'JOB_ATTEMPTS=3')
        ->call('submit');

    $remaining = EnvironmentVariable::where('resourceable_type', Service::class)
        ->where('resourceable_id', $this->service->id)
        ->where('key', 'JOB_ATTEMPTS')
        ->get();

    expect($remaining)->toHaveCount(1);
    expect($remaining->first()->value)->toBe('3');
});

it('still blocks removing a Docker-Compose-required key entirely on bulk save', function () {
    createServiceEnvironmentVariable($this->service, 'JOB_ATTEMPTS', '3');
    createServiceEnvironmentVariable($this->service, 'OTHER_KEY', 'other');

    Livewire::test(All::class, ['resource' => $this->service])
        ->set('variables', 'OTHER_KEY=other')
        ->call('submit')
        ->assertDispatched('error');

    expect(EnvironmentVariable::where('resourceable_type', Service::class)
        ->where('resourceable_id', $this->service->id)
        ->where('key', 'JOB_ATTEMPTS')
        ->count())->toBe(1);
});

it('deduplicates legacy rows in the migration keeping the most recently updated one', function () {
    [$oldest, $middle, $newest] = seedLegacyDuplicates($this->service, 'JOB_ATTEMPTS', 3);

    DB::table('environment_variables')->where('id', $oldest->id)->update(['updated_at' => now()->subDays(2)]);
    DB::table('environment_variables')->where('id', $middle->id)->update(['updated_at' => now()]);
    DB::table('environment_variables')->where('id', $newest->id)->update(['updated_at' => now()->subDay()]);

    $migration = include database_path('migrations/2026_08_04_000000_add_unique_constraint_to_environment_variables_table.php');
    $migration->up();

    $remaining = EnvironmentVariable::where('resourceable_type', Service::class)
        ->where('resourceable_id', $this->service->id)
        ->where('key', 'JOB_ATTEMPTS')
        ->get();

    // $middle has the latest updated_at, so it survives despite not having the highest id
    expect($remaining)->toHaveCount(1);
    expect($remaining->first()->id)->toBe($middle->id);
    expect(Schema::hasIndex('environment_variables', 'env_vars_resourceable_key_preview_unique'))->toBeTrue();
});
