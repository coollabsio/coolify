<?php

use App\Models\Application;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\SharedEnvironmentVariable;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use App\Services\SharedVariableCanvas;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(['id' => 0]));
    Server::flushIdentityMap();

    $this->team = Team::factory()->create(['show_boarding' => false]);
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'member']);

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->project = Project::factory()->create(['team_id' => $this->team->id, 'name' => 'Shop']);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
    $destination = StandaloneDocker::query()->where('server_id', $this->server->id)->firstOrFail();
    $this->application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
});

function overviewShared(array $attributes): SharedEnvironmentVariable
{
    return SharedEnvironmentVariable::create(['value' => 'secret-value', ...$attributes]);
}

function overviewEnv(Application $application, string $key, string $value): void
{
    EnvironmentVariable::create([
        'key' => $key, 'value' => $value,
        'resourceable_type' => $application->getMorphClass(), 'resourceable_id' => $application->id,
    ]);
}

test('the overview counts variables per scope and the resources that use each one', function () {
    overviewShared(['key' => 'API_TOKEN', 'type' => 'team', 'team_id' => $this->team->id]);
    overviewShared(['key' => 'DB_URL', 'type' => 'project', 'team_id' => $this->team->id, 'project_id' => $this->project->id]);
    overviewShared(['key' => 'REGION', 'type' => 'server', 'team_id' => $this->team->id, 'server_id' => $this->server->id]);
    overviewEnv($this->application, 'TOKEN', '{{team.API_TOKEN}}');
    overviewEnv($this->application, 'DATABASE', 'postgres://{{ project.DB_URL }}');
    overviewEnv($this->application, 'OLD', '{{team.REMOVED}}');

    $overview = app(SharedVariableCanvas::class)->overview($this->team);

    expect($overview['scopes']['team'])->toBe(['variables' => 1, 'owners' => 1])
        ->and($overview['scopes']['project'])->toBe(['variables' => 1, 'owners' => 1])
        ->and($overview['scopes']['environment'])->toBe(['variables' => 0, 'owners' => 0])
        ->and($overview['used']->pluck('resources', 'key')->all())->toBe(['API_TOKEN' => 1, 'DB_URL' => 1])
        ->and($overview['unused']->pluck('key')->all())->toBe(['REGION'])
        ->and($overview['missing'])->toBe(1);
});

test('a reference resolves against the resource own project, not another project with the same key', function () {
    $otherProject = Project::factory()->create(['team_id' => $this->team->id]);
    overviewShared(['key' => 'DB_URL', 'type' => 'project', 'team_id' => $this->team->id, 'project_id' => $otherProject->id]);
    overviewEnv($this->application, 'DATABASE', '{{project.DB_URL}}');

    $overview = app(SharedVariableCanvas::class)->overview($this->team);

    expect($overview['used'])->toBeEmpty()
        ->and($overview['missing'])->toBe(1);
});

test('the overview page shows only the current team and never the values', function () {
    overviewShared(['key' => 'API_TOKEN', 'type' => 'team', 'team_id' => $this->team->id]);
    $otherTeam = Team::factory()->create();
    overviewShared(['key' => 'OTHER_TEAM_KEY', 'type' => 'team', 'team_id' => $otherTeam->id]);

    $this->actingAs($this->user)->withSession(['currentTeam' => $this->team])
        ->get(route('shared-variables.index'))
        ->assertSuccessful()
        ->assertSee('API_TOKEN')
        ->assertDontSee('OTHER_TEAM_KEY')
        ->assertDontSee('secret-value');
});
