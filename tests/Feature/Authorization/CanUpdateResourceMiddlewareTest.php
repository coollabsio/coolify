<?php

use App\Http\Middleware\CanUpdateResource;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

uses(RefreshDatabase::class);

function requestWithCanUpdateResourceRouteParameter(string $parameter, ?string $value): Request
{
    $parameters = [
        'application_uuid' => null,
        'database_uuid' => null,
        'stack_service_uuid' => null,
        'service_uuid' => null,
        'server_uuid' => null,
        'environment_uuid' => null,
        'project_uuid' => null,
        $parameter => $value,
    ];

    $request = Mockery::mock(Request::class)->makePartial();
    $request->shouldReceive('route')->andReturnUsing(fn (string $key): ?string => $parameters[$key] ?? null);

    return $request;
}

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::updateOrCreate(['id' => 0], ['id' => 0]));

    $this->team = Team::factory()->create();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->server = Server::factory()->create(['team_id' => $this->team->id]);

    $this->admin = User::factory()->create();
    $this->admin->teams()->attach($this->team, ['role' => 'admin']);

    $this->member = User::factory()->create();
    $this->member->teams()->attach($this->team, ['role' => 'member']);
});

it('blocks members from update-only project routes before the page renders', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    (new CanUpdateResource)->handle(
        requestWithCanUpdateResourceRouteParameter('project_uuid', $this->project->uuid),
        fn () => response('ok')
    );
})->throws(HttpException::class, 'You do not have permission to update this resource.');

it('allows admins through update-only project routes', function () {
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    $response = (new CanUpdateResource)->handle(
        requestWithCanUpdateResourceRouteParameter('project_uuid', $this->project->uuid),
        fn () => response('ok')
    );

    expect($response->getContent())->toBe('ok');
});

it('blocks members from update-only server routes before the page renders', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    (new CanUpdateResource)->handle(
        requestWithCanUpdateResourceRouteParameter('server_uuid', $this->server->uuid),
        fn () => response('ok')
    );
})->throws(HttpException::class, 'You do not have permission to update this resource.');

it('returns not found when an update-only route references an unknown resource', function () {
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    (new CanUpdateResource)->handle(
        requestWithCanUpdateResourceRouteParameter('project_uuid', 'not-a-project'),
        fn () => response('ok')
    );
})->throws(NotFoundHttpException::class, 'Resource not found.');
