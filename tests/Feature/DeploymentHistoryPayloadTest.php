<?php

use App\Enums\ApplicationDeploymentStatus;
use App\Livewire\Project\Application\Deployment\Index;
use App\Livewire\Project\Application\Deployment\Show;
use App\Livewire\Project\Application\Heading;
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

    $this->largeLog = json_encode([[
        'command' => null,
        'output' => str_repeat('large deployment log ', 20000),
        'type' => 'stdout',
        'timestamp' => '2026-10-01T10:00:00.000000Z',
        'hidden' => false,
        'batch' => 1,
        'order' => 1,
    ]]);
    $this->createDeployment = fn (string $uuid, string $createdAt, array $overrides = []) => ApplicationDeploymentQueue::query()->forceCreate([
        'application_id' => $this->application->id,
        'deployment_uuid' => $uuid,
        'server_id' => $server->id,
        'server_name' => $server->name,
        'status' => ApplicationDeploymentStatus::FINISHED->value,
        'commit' => 'abcdef1234567890',
        'commit_message' => "Message of {$uuid}",
        'logs' => $this->largeLog,
        'configuration_snapshot' => ['fqdn' => 'https://example.com', 'docker_compose_raw' => str_repeat('x', 100000)],
        'configuration_diff' => ['fqdn' => ['old' => null, 'new' => 'https://example.com']],
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
        ...$overrides,
    ]);

    $this->deploymentQueries = collect();
    $this->recordDeploymentQueries = fn () => DB::listen(function ($query) {
        if (str_contains($query->sql, 'application_deployment_queues')) {
            $this->deploymentQueries->push($query->sql);
        }
    });
    $this->assertNoPayloadQueries = function () {
        expect($this->deploymentQueries)->not->toBeEmpty();
        $this->deploymentQueries->each(fn (string $sql) => expect($sql)
            ->not->toMatch('/select\s+("?\w+"?\.)?\*/i')
            ->not->toContain('logs')
            ->not->toContain('configuration_snapshot')
            ->not->toContain('configuration_diff'));
    };
});

function otherTeamDeployment(): array
{
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $destination = StandaloneDocker::query()->where('server_id', $server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
    $deployment = ApplicationDeploymentQueue::query()->forceCreate([
        'application_id' => $application->id,
        'deployment_uuid' => 'other-team-deployment',
        'server_id' => $server->id,
        'status' => ApplicationDeploymentStatus::FINISHED->value,
        'logs' => '[{"output":"other team log"}]',
    ]);

    return [
        'project_uuid' => $project->uuid,
        'environment_uuid' => $environment->uuid,
        'application_uuid' => $application->uuid,
        'deployment_uuid' => $deployment->deployment_uuid,
    ];
}

it('does not load deployment payload columns for the history list on any Livewire request', function () {
    foreach (range(1, 4) as $number) {
        ($this->createDeployment)("history-d{$number}", "2026-10-0{$number} 10:00:00");
    }
    ($this->createDeployment)('history-d5', '2026-10-05 10:00:00', [
        'status' => ApplicationDeploymentStatus::FAILED->value,
        'pull_request_id' => 7,
    ]);
    ($this->recordDeploymentQueries)();

    Livewire::test(Index::class, [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
        'application_uuid' => $this->application->uuid,
        'embedded' => true,
    ])
        ->assertSeeInOrder(['history-d5', 'history-d4', 'history-d3'])
        ->assertSee('Message of history-d5')
        ->assertDontSee('history-d2')
        ->call('reloadDeployments')
        ->assertSee('history-d5')
        ->call('nextPage')
        ->assertSeeInOrder(['history-d2', 'history-d1'])
        ->assertDontSee('history-d5')
        ->call('previousPage')
        ->assertSee('history-d5')
        ->assertDontSee('history-d2')
        ->call('goToPage', 2)
        ->assertSee('history-d1')
        ->call('toggleDeploymentFilter', 'status:failed')
        ->assertSee('history-d5')
        ->assertDontSee('history-d4')
        ->call('toggleDeploymentFilter', 'status:failed')
        ->call('setPullRequestFilter', '7')
        ->assertSee('history-d5')
        ->assertDontSee('history-d4')
        ->call('clearFilter')
        ->set('search', 'history-d3')
        ->assertSee('history-d3')
        ->assertDontSee('history-d4')
        ->set('search', '')
        ->call('setDeploymentSort', 'oldest')
        ->assertSeeInOrder(['history-d1', 'history-d2', 'history-d3'])
        ->assertDontSee('history-d5')
        ->set('defaultTake', 10)
        ->assertSee('history-d5')
        ->assertSee('history-d1')
        ->call('$refresh')
        ->assertSee('history-d5')
        ->dispatch('deploymentSelected', deploymentUuid: 'history-d2')
        ->assertSet('selectedDeploymentUuid', 'history-d2')
        ->assertSee('history-d2');

    ($this->assertNoPayloadQueries)();
});

it('flips the deployment sort from the started column header', function () {
    foreach (range(1, 3) as $number) {
        ($this->createDeployment)("header-d{$number}", "2026-10-0{$number} 10:00:00");
    }

    Livewire::test(Index::class, [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
        'application_uuid' => $this->application->uuid,
    ])
        ->assertSeeHtml('aria-label="Sort by started, sorted descending"')
        ->assertSeeInOrder(['header-d3', 'header-d2', 'header-d1'])
        ->call('setDeploymentSort', 'oldest')
        ->assertSeeHtml('aria-label="Sort by started, sorted ascending"')
        ->assertSeeInOrder(['header-d1', 'header-d2', 'header-d3']);
});

it('does not show the deployment history of another team', function () {
    $otherTeam = otherTeamDeployment();
    unset($otherTeam['deployment_uuid']);

    Livewire::test(Index::class, $otherTeam)
        ->assertRedirect(route('dashboard'))
        ->assertDontSee('other-team-deployment');
});

it('loads the full logs and configuration when an authorized user opens a deployment', function () {
    ($this->createDeployment)('full-detail', '2026-10-01 10:00:00');

    $component = Livewire::test(Show::class, [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
        'application_uuid' => $this->application->uuid,
        'deployment_uuid' => 'full-detail',
    ])->call('polling');

    $deployment = $component->get('application_deployment_queue');
    expect($deployment->logs)->toBe($this->largeLog)
        ->and($deployment->configuration_snapshot['docker_compose_raw'])->toBe(str_repeat('x', 100000));
});

it('does not open a deployment of another team', function () {
    Livewire::test(Show::class, otherTeamDeployment())
        ->assertRedirect(route('dashboard'))
        ->assertDontSee('other team log');
});

it('loads only the commit of the last successful deployment for the application heading', function () {
    ($this->createDeployment)('heading-deployment', '2026-10-01 10:00:00', ['commit_message' => 'Heading commit']);
    ($this->recordDeploymentQueries)();

    Livewire::test(Heading::class, ['application' => $this->application])
        ->assertSet('lastDeploymentInfo', 'abcdef1... Heading commit');

    ($this->assertNoPayloadQueries)();
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
        ->and($full->logs)->toBe($this->largeLog)
        ->and($full->configuration_snapshot['fqdn'])->toBe('https://example.com');
});
