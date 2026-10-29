<?php

use App\Enums\ApplicationDeploymentStatus;
use App\Livewire\Project\Application\Deployment\Index;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0]));

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $destination = StandaloneDocker::query()->where('server_id', $server->id)->firstOrFail();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
    $this->application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);

    $this->createDeployment = fn (string $uuid, string $createdAt) => ApplicationDeploymentQueue::query()->forceCreate([
        'application_id' => $this->application->id,
        'deployment_uuid' => $uuid,
        'server_id' => $server->id,
        'server_name' => $server->name,
        'status' => ApplicationDeploymentStatus::FINISHED->value,
        'commit' => 'abcdef1234567890',
        'commit_message' => "Message of {$uuid}",
        'logs' => '[{"output":"large deployment log"}]',
        'configuration_snapshot' => ['fqdn' => 'https://example.com'],
        'configuration_diff' => ['fqdn' => ['old' => null, 'new' => 'https://example.com']],
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);
});

it('does not load deployment payload columns for the history list on any Livewire request', function () {
    ($this->createDeployment)('deployment-history-row', '2026-10-01 10:00:00');

    $historyQueries = [];
    DB::listen(function ($query) use (&$historyQueries) {
        if (str_contains($query->sql, 'application_deployment_queues')) {
            $historyQueries[] = $query->sql;
        }
    });

    Livewire::test(Index::class, [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
        'application_uuid' => $this->application->uuid,
        'embedded' => true,
    ])
        ->assertSee('deployment-history-row')
        ->assertSee('Message of deployment-history-row')
        ->call('reloadDeployments')
        ->assertSee('deployment-history-row')
        ->call('setDeploymentSort', 'oldest')
        ->assertSee('deployment-history-row');

    expect($historyQueries)->not->toBeEmpty();
    foreach ($historyQueries as $sql) {
        expect($sql)
            ->not->toMatch('/select\s+("?\w+"?\.)?\*/i')
            ->not->toContain('logs')
            ->not->toContain('configuration_snapshot')
            ->not->toContain('configuration_diff');
    }
});

it('orders and pages deployments with equal timestamps by id in both directions', function () {
    $uuids = collect(['first', 'second', 'third', 'fourth', 'fifth'])
        ->each(fn (string $uuid) => ($this->createDeployment)($uuid, '2026-10-01 10:00:00'));

    $page = fn (string $sort, int $skip) => $this->application
        ->deployments(skip: $skip, take: 2, sort: $sort, columns: Index::HISTORY_COLUMNS)['deployments']
        ->pluck('deployment_uuid')
        ->all();

    expect([...$page('newest', 0), ...$page('newest', 2), ...$page('newest', 4)])->toBe($uuids->reverse()->values()->all())
        ->and([...$page('oldest', 0), ...$page('oldest', 2), ...$page('oldest', 4)])->toBe($uuids->all());
});

it('keeps the full deployment payload for callers that do not restrict the columns', function () {
    ($this->createDeployment)('full-deployment', '2026-10-01 10:00:00');

    $listed = $this->application->deployments(columns: Index::HISTORY_COLUMNS)['deployments']->sole();
    $full = $this->application->deployments()['deployments']->sole();

    expect($listed->getAttributes())->not->toHaveKeys(['logs', 'configuration_snapshot', 'configuration_diff'])
        ->and($full->logs)->toBe('[{"output":"large deployment log"}]')
        ->and($full->configuration_snapshot)->toBe(['fqdn' => 'https://example.com']);
});
