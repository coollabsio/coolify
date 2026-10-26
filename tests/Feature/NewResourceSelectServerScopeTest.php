<?php

use App\Livewire\Project\New\Select;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

function createUsableSelectServer(Team $team): Server
{
    $server = Server::factory()->create(['team_id' => $team->id]);
    $server->settings()->update([
        'is_reachable' => true,
        'is_usable' => true,
        'is_build_server' => false,
        'is_swarm_worker' => false,
        'force_disabled' => false,
    ]);

    return $server->refresh();
}

beforeEach(function () {
    Server::flushIdentityMap();
    InstanceSettings::forceCreate(['id' => 0]);

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $this->server = createUsableSelectServer($this->team);
    $this->defaultDestination = StandaloneDocker::query()->where('server_id', $this->server->id)->firstOrFail();
    $this->secondDestination = StandaloneDocker::factory()->create([
        'server_id' => $this->server->id,
        'network' => 'second-network',
    ]);

    $this->otherTeam = Team::factory()->create();
    $this->otherServer = createUsableSelectServer($this->otherTeam);
    $this->otherDestination = StandaloneDocker::query()->where('server_id', $this->otherServer->id)->firstOrFail();

    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);

    $parameters = [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
    ];
    Event::listen(RouteMatched::class, function (RouteMatched $event) use ($parameters): void {
        foreach ($parameters as $name => $value) {
            $event->route->setParameter($name, $value);
        }
    });
});

afterEach(function () {
    Server::flushIdentityMap();
});

test('selecting a server that is not in the server list returns not found and loads nothing', function () {
    $component = Livewire::test(Select::class)->call('loadServers');
    $select = $component->instance();

    expect(fn () => $select->setServer($this->otherServer->id))
        ->toThrow(fn (HttpException $exception) => expect($exception->getStatusCode())->toBe(404))
        ->and($select->server)->toBeNull()
        ->and($select->standaloneDockers)->toBeNull()
        ->and($select->swarmDockers)->toBeNull()
        ->and($select->current_step)->toBe('type');

    $component->call('setServer', $this->otherServer->id)->assertNotFound();
});

test('selecting a listed server shows its destinations', function () {
    $component = Livewire::test(Select::class)
        ->call('loadServers')
        ->call('setServer', $this->server->id)
        ->assertOk()
        ->assertSet('server_id', (string) $this->server->id)
        ->assertSet('current_step', 'destinations');

    expect($component->get('server')->id)->toBe($this->server->id)
        ->and($component->get('standaloneDockers')->pluck('uuid')->all())
        ->toEqualCanonicalizing([$this->defaultDestination->uuid, $this->secondDestination->uuid]);
});

test('selecting a destination of the selected server continues to the create page', function () {
    Livewire::test(Select::class)
        ->call('loadServers')
        ->set('type', 'dockerfile')
        ->call('setServer', $this->server->id)
        ->call('setDestination', $this->secondDestination->uuid)
        ->assertRedirect(route('project.resource.create', [
            'project_uuid' => $this->project->uuid,
            'environment_uuid' => $this->environment->uuid,
            'type' => 'dockerfile',
            'destination' => $this->secondDestination->uuid,
            'server_id' => $this->server->id,
        ]));
});

test('selecting a destination that is not on the selected server returns not found', function () {
    Livewire::test(Select::class)
        ->call('loadServers')
        ->set('type', 'dockerfile')
        ->call('setServer', $this->server->id)
        ->call('setDestination', $this->otherDestination->uuid)
        ->assertNotFound()
        ->assertNoRedirect();
});

test('the postgresql shortcut ignores a server that is not in the server list', function () {
    Livewire::withQueryParams([
        'type' => 'postgresql',
        'server_id' => $this->otherServer->id,
        'destination' => $this->otherDestination->uuid,
    ])
        ->test(Select::class)
        ->assertSet('server', null)
        ->assertSet('current_step', 'type');
});
