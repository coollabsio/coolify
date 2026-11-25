<?php

use App\Jobs\EvaluatePush;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use App\Services\DeployTriggers\EvaluationSlots;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeAffectedRunner;

/**
 * A team with an owner, one functional server, and one environment.
 *
 * @return array{team: Team, user: User, server: Server, destination: StandaloneDocker, environment: Environment}
 */
function deployTriggerFixture(string $role = 'owner'): array
{
    InstanceSettings::unguarded(fn () => InstanceSettings::updateOrCreate(['id' => 0], []));

    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->members()->attach($user->id, ['role' => $role]);

    $server = Server::factory()->create(['team_id' => $team->id]);
    $server->settings->update(['is_reachable' => true, 'is_usable' => true, 'force_disabled' => false]);
    $destination = StandaloneDocker::query()->where('server_id', $server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    return compact('team', 'user', 'server', 'destination', 'environment');
}

/**
 * @param  array{destination: StandaloneDocker, environment: Environment}  $fixture
 * @param  array<string, mixed>  $attributes
 */
function deployTriggerApplication(array $fixture, array $attributes = []): Application
{
    return Application::factory()->create(array_merge([
        'git_repository' => 'acme/mono',
        'git_branch' => 'main',
        'environment_id' => $fixture['environment']->id,
        'destination_id' => $fixture['destination']->id,
        'destination_type' => $fixture['destination']->getMorphClass(),
    ], $attributes));
}

/**
 * Runs the EvaluatePush jobs that a webhook pushed to a faked queue, with a helper that cannot evaluate
 * (so the legacy watch-paths check and immediate deploys apply), and returns how many ran.
 */
function runPushedEvaluations(?Closure $runner = null): int
{
    app()->instance(EvaluationSlots::class, new class extends EvaluationSlots
    {
        public function run(Closure $callback, Closure $whenBusy): void
        {
            $callback();
        }
    });
    $runner === null ? FakeAffectedRunner::unsupported() : $runner();

    $jobs = Queue::pushed(EvaluatePush::class);
    $jobs->each(fn (EvaluatePush $job) => $job->handle());

    return $jobs->count();
}
