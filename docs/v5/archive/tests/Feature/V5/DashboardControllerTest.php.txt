<?php

use App\Events\V5RealtimeTestEvent;
use App\Models\Team;
use App\Models\User;
use App\Models\V5\Application;
use App\Models\V5\Application as V5Application;
use App\Models\V5\Server as V5Server;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    resetV5DashboardTestState();
});

it('serves the v5 inertia shell', function () {
    app()->detectEnvironment(fn () => 'local');

    $this->withoutVite();
    $this->withoutExceptionHandling();
    fakeFluxHealth();
    createSharedUserAndTeamTables();

    $user = User::withoutEvents(fn () => User::query()->create([
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
        'email_verified_at' => now(),
        'password' => 'password',
    ]));
    $team = Team::withoutEvents(fn () => Team::query()->create([
        'name' => 'V5 Shared Team',
        'description' => 'Shared team details',
        'personal_team' => true,
        'show_boarding' => false,
    ]));
    $user->teams()->attach($team, ['role' => 'owner']);

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get('/v5')
        ->assertSuccessful()
        ->assertSee('<html lang="en" class="dark">', false)
        ->assertSee('coolify-logo-dev-transparent.png', false)
        ->assertDontSee('coolify-logo.svg', false)
        ->assertSee('js/echo.js', false)
        ->assertSee('js/pusher.js', false)
        ->assertSee('window.Echo', false)
        ->assertSee('v5-app', false)
        ->assertSee('Dashboard', false)
        ->assertDontSee('Home', false)
        ->assertDontSee('v5-ready', false)
        ->assertDontSee('This page is served from Laravel through Inertia and React')
        ->assertDontSee('Bootstrap server')
        ->assertDontSee('privateKeys', false)
        ->assertSee('Running')
        ->assertSee('Flux is running.')
        ->assertDontSee('"clusters":', false)
        ->assertDontSee('cooldServers', false)
        ->assertDontSee('coold-dev')
        ->assertDontSee('Create cluster')
        ->assertDontSee('Cluster details')
        ->assertDontSee('100.64.0.1')
        ->assertDontSee('Current team')
        ->assertDontSee('Your teams')
        ->assertSee('currentTeam', false)
        ->assertSee('"currentTeam":{"id":'.$team->id, false)
        ->assertDontSee('teams', false)
        ->assertDontSee('V5 Shared Team')
        ->assertDontSee('Shared team details');
});

it('serves v5 dashboard applications as canvas nodes', function () {
    app()->detectEnvironment(fn () => 'local');

    $this->withoutVite();
    fakeFluxHealth();
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    [$project, $environment] = createV5ProjectWithEnvironment($team, 'Production Project', 'Production');
    [$otherProject, $otherEnvironment] = createV5ProjectWithEnvironment($team, 'Staging Project', 'Staging');
    $server = V5Server::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'name' => 'edge-01',
        'host' => '203.0.113.10',
        'ssh_user' => 'root',
        'ssh_port' => 22,
        'status' => 'installed',
        'capabilities' => [],
    ]);

    $application = V5Application::query()->create([
        'team_id' => $team->id,
        'project_id' => $project->id,
        'environment_id' => $environment->id,
        'server_id' => $server->id,
        'created_by_user_id' => $user->id,
        'name' => 'nginx-test',
        'image' => 'docker.io/library/nginx:alpine',
        'container_name' => 'coolify-v5-nginx-1',
        'status' => 'running',
        'status_message' => 'Container started.',
        'runtime_container_id' => 'abc123',
        'mesh_namespace' => 'default',
        'canvas_x' => 120,
        'canvas_y' => -80,
    ]);
    $application = V5Application::query()->create([
        'team_id' => $team->id,
        'project_id' => $otherProject->id,
        'environment_id' => $otherEnvironment->id,
        'server_id' => $server->id,
        'created_by_user_id' => $user->id,
        'name' => 'other-nginx-test',
        'image' => 'docker.io/library/nginx:alpine',
        'container_name' => 'coolify-v5-nginx-other',
        'status' => 'running',
    ]);

    $this
        ->actingAs($user)
        ->withSession([
            'currentTeam' => $team,
            'v5.selectedProjectUuid' => $project->uuid,
            'v5.selectedEnvironmentUuid' => $environment->uuid,
        ])
        ->get('/v5')
        ->assertSuccessful()
        ->assertSee('"applications":[', false)
        ->assertSee('"name":"nginx-test"', false)
        ->assertSee('"serverName":"edge-01"', false)
        ->assertSee('"meshNamespace":"default"', false)
        ->assertSee('"meshFqdn":"coolify-v5-nginx-1.default.coolify.internal"', false)
        ->assertSee('"canvasX":120', false)
        ->assertSee('"canvasY":-80', false)
        ->assertDontSee('other-nginx-test', false);
});

it('marks v5 application status as unknown when its server is unreachable', function () {
    app()->detectEnvironment(fn () => 'local');

    $this->withoutVite();
    fakeFluxHealth();
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    [$project, $environment] = createV5ProjectWithEnvironment($team, 'Production Project', 'Production');
    $server = V5Server::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'name' => 'edge-01',
        'host' => '203.0.113.10',
        'ssh_user' => 'root',
        'ssh_port' => 22,
        'status' => 'unreachable',
        'last_status_output' => 'coold heartbeat timed out.',
        'capabilities' => [],
    ]);

    V5Application::query()->create([
        'team_id' => $team->id,
        'project_id' => $project->id,
        'environment_id' => $environment->id,
        'server_id' => $server->id,
        'created_by_user_id' => $user->id,
        'name' => 'nginx-test',
        'image' => 'docker.io/library/nginx:alpine',
        'container_name' => 'coolify-v5-nginx-1',
        'status' => 'running',
        'status_message' => 'Container started.',
    ]);

    $this
        ->actingAs($user)
        ->withSession([
            'currentTeam' => $team,
            'v5.selectedProjectUuid' => $project->uuid,
            'v5.selectedEnvironmentUuid' => $environment->uuid,
        ])
        ->get('/v5')
        ->assertSuccessful()
        ->assertSee('"status":"running"', false)
        ->assertSee('"effectiveStatus":"unknown"', false)
        ->assertSee('"effectiveStatusMessage":"coold heartbeat timed out."', false)
        ->assertSee('"serverStatus":"unreachable"', false)
        ->assertSee('"isServerReachable":false', false);
});

it('shows v5 caddy ingress as unreachable when its server is unreachable', function () {
    app()->detectEnvironment(fn () => 'local');

    $this->withoutVite();
    fakeFluxHealth();
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    V5Server::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'name' => 'edge-ingress-01',
        'host' => '203.0.113.20',
        'ssh_user' => 'root',
        'ssh_port' => 22,
        'status' => 'unreachable',
        'ingress_status' => 'running',
        'last_status_output' => 'coold heartbeat timed out.',
        'capabilities' => ['ingress'],
    ]);

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get('/v5')
        ->assertSuccessful()
        ->assertSee('"caddyIngresses":[', false)
        ->assertSee('"status":"unreachable"', false)
        ->assertSee('"statusMessage":"coold heartbeat timed out."', false);
});

it('serves enabled v5 caddy ingress servers as canvas nodes', function () {
    app()->detectEnvironment(fn () => 'local');

    $this->withoutVite();
    fakeFluxHealth();
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    createV5ProjectWithEnvironment($team, 'Production Project', 'Production');

    V5Server::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'name' => 'edge-ingress-01',
        'host' => '203.0.113.20',
        'ssh_user' => 'root',
        'ssh_port' => 22,
        'status' => 'installed',
        'ingress_status' => 'running',
        'capabilities' => ['ingress'],
        'canvas_x' => -160,
        'canvas_y' => 240,
    ]);
    V5Server::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'name' => 'edge-ingress-02',
        'host' => '203.0.113.22',
        'ssh_user' => 'root',
        'ssh_port' => 22,
        'status' => 'installed',
        'ingress_status' => 'exited',
        'capabilities' => ['ingress'],
    ]);
    V5Server::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'name' => 'edge-worker-01',
        'host' => '203.0.113.21',
        'ssh_user' => 'root',
        'ssh_port' => 22,
        'status' => 'installed',
        'capabilities' => [],
    ]);

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get('/v5')
        ->assertSuccessful()
        ->assertSee('"caddyIngresses":[', false)
        ->assertSee('"name":"edge-ingress-01"', false)
        ->assertSee('"host":"203.0.113.20"', false)
        ->assertSee('"type":"caddy"', false)
        ->assertSee('"status":"running"', false)
        ->assertSee('"name":"edge-ingress-02"', false)
        ->assertSee('"status":"exited"', false)
        ->assertSee('"canvasX":-160', false)
        ->assertSee('"canvasY":240', false);
});

it('broadcasts manual v5 realtime test events immediately', function () {
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();

    Event::fake([V5RealtimeTestEvent::class]);

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->postJson('/v5/realtime-test', [
            'message' => 'hello socket',
        ])
        ->assertAccepted()
        ->assertJsonPath('message', 'Realtime test event broadcasted.');

    Event::assertDispatched(V5RealtimeTestEvent::class, fn (V5RealtimeTestEvent $event): bool => $event->teamId === $team->id
        && $event->message === 'hello socket'
        && $event->broadcastAs() === 'v5.realtime.test'
        && $event instanceof ShouldBroadcastNow);
});

it('serves the production v5 favicon outside local environments', function () {
    app()->detectEnvironment(fn () => 'production');

    $this->withoutVite();
    fakeFluxHealth();
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get('/v5')
        ->assertSuccessful()
        ->assertSee('coolify-logo.svg', false)
        ->assertDontSee('coolify-logo-dev-transparent.png', false);
});

it('shares existing projects and environments with the v5 dashboard page', function () {
    $this->withoutVite();
    fakeFluxHealth();
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    [$project, $environment] = createV5ProjectWithEnvironment($team, 'gravy-truck', 'production');
    createV5ProjectWithEnvironment($team, 'rocket-bike', 'staging');
    $otherTeam = Team::withoutEvents(fn () => Team::query()->create([
        'name' => 'Other V5 Team',
        'description' => null,
        'personal_team' => false,
        'show_boarding' => false,
    ]));
    [$otherProject, $otherEnvironment] = createV5ProjectWithEnvironment($otherTeam, 'secret-saucer', 'private');

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get('/v5')
        ->assertSuccessful()
        ->assertSee('"projects":[', false)
        ->assertSee('"name":"gravy-truck"', false)
        ->assertSee('"uuid":"'.$project->uuid.'"', false)
        ->assertSee('"environments":[', false)
        ->assertSee('"name":"production"', false)
        ->assertSee('"uuid":"'.$environment->uuid.'"', false)
        ->assertSee('"selectedProjectUuid":"'.$project->uuid.'"', false)
        ->assertSee('"selectedEnvironmentUuid":"'.$environment->uuid.'"', false)
        ->assertDontSee('"id":"'.$project->id.'","uuid":"'.$project->uuid.'"', false)
        ->assertDontSee('"id":"'.$environment->id.'","uuid":"'.$environment->uuid.'"', false)
        ->assertDontSee($otherProject->name)
        ->assertDontSee($otherProject->uuid)
        ->assertDontSee($otherEnvironment->name)
        ->assertDontSee($otherEnvironment->uuid);
});

it('opens a linked v5 application in its project and environment', function () {
    $this->withoutVite();
    fakeFluxHealth();
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    [$project, $environment] = createV5ProjectWithEnvironment($team, 'linked-project', 'production');
    $application = Application::query()->create([
        'team_id' => $team->id,
        'project_id' => $project->id,
        'environment_id' => $environment->id,
        'created_by_user_id' => $user->id,
        'name' => 'linked-nginx',
        'image' => 'nginx:alpine',
        'container_name' => 'linked-v5-nginx',
    ]);

    $this->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get(route('v5.dashboard', [
            'project' => $project->uuid,
            'environment' => $environment->uuid,
            'application' => $application->uuid,
        ]))
        ->assertSuccessful()
        ->assertSee('"selectedProjectUuid":"'.$project->uuid.'"', false)
        ->assertSee('"selectedEnvironmentUuid":"'.$environment->uuid.'"', false)
        ->assertSee('"selectedApplicationUuid":"'.$application->uuid.'"', false);
});

it('persists the selected v5 project and environment in the session', function () {
    $this->withoutVite();
    fakeFluxHealth();
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    createV5ProjectWithEnvironment($team, 'alpha-project', 'production');
    [$selectedProject, $selectedEnvironment] = createV5ProjectWithEnvironment($team, 'zebra-project', 'staging');

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->postJson('/v5/selection', [
            'project_uuid' => $selectedProject->uuid,
            'environment_uuid' => $selectedEnvironment->uuid,
        ])
        ->assertNoContent()
        ->assertSessionHas('v5.selectedProjectUuid', $selectedProject->uuid)
        ->assertSessionHas('v5.selectedEnvironmentUuid', $selectedEnvironment->uuid);

    $this
        ->actingAs($user)
        ->get('/v5')
        ->assertSuccessful()
        ->assertSee('"selectedProjectUuid":"'.$selectedProject->uuid.'"', false)
        ->assertSee('"selectedEnvironmentUuid":"'.$selectedEnvironment->uuid.'"', false);
});

it('rejects persisted v5 selections outside the current team', function () {
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    $otherTeam = Team::withoutEvents(fn () => Team::query()->create([
        'name' => 'Other V5 Team',
        'description' => null,
        'personal_team' => false,
        'show_boarding' => false,
    ]));
    [$otherProject, $otherEnvironment] = createV5ProjectWithEnvironment($otherTeam, 'secret-saucer', 'private');

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->postJson('/v5/selection', [
            'project_uuid' => $otherProject->uuid,
            'environment_uuid' => $otherEnvironment->uuid,
        ])
        ->assertForbidden()
        ->assertSessionMissing('v5.selectedProjectUuid')
        ->assertSessionMissing('v5.selectedEnvironmentUuid');
});

it('serves v5 dev assets from the current request host', function (string $url, string $viteHost) {
    fakeFluxHealth();
    createSharedUserAndTeamTables();
    [$user, $team] = createV5UserWithTeam();

    $hotFile = public_path('hot');
    $originalHotFile = file_exists($hotFile) ? file_get_contents($hotFile) : null;

    file_put_contents($hotFile, 'http://configured-vite-host.test:5173');

    try {
        $response = $this
            ->actingAs($user)
            ->withSession(['currentTeam' => $team])
            ->get($url);

        $response
            ->assertSuccessful()
            ->assertSee("import RefreshRuntime from 'http://{$viteHost}:5173/@react-refresh'", false)
            ->assertSee("src=\"http://{$viteHost}:5173/@vite/client\"", false)
            ->assertSee("src=\"http://{$viteHost}:5173/resources/js/v5/app.tsx\"", false)
            ->assertDontSee('configured-vite-host.test', false);
    } finally {
        if ($originalHotFile === null) {
            @unlink($hotFile);
        } else {
            file_put_contents($hotFile, $originalHotFile);
        }
    }
})->with([
    'localhost' => ['http://localhost:8000/v5', 'localhost'],
    'tailscale ip' => ['http://100.64.0.10:8000/v5', '100.64.0.10'],
]);

it('configures the vite dev server for remote v5 access', function () {
    $viteConfig = file_get_contents(base_path('vite.config.js'));

    expect($viteConfig)
        ->toContain('host: "0.0.0.0"')
        ->toContain('allowedHosts: true')
        ->toContain('cors: true')
        ->toContain('const viteHmrHost = env.VITE_HMR_HOST || null;')
        ->toContain('hmr: viteHmrHost')
        ->not->toContain('hmr: viteHost');
});

it('shows when flux is unavailable', function () {
    $this->withoutVite();
    fakeFluxHealth(false, 'Flux socket was not found.');
    createSharedUserAndTeamTables();

    $user = User::withoutEvents(fn () => User::query()->create([
        'name' => 'Katherine Johnson',
        'email' => 'katherine@example.com',
        'email_verified_at' => now(),
        'password' => 'password',
    ]));
    $team = Team::withoutEvents(fn () => Team::query()->create([
        'name' => 'Flux Test Team',
        'description' => null,
        'personal_team' => false,
        'show_boarding' => false,
    ]));
    $user->teams()->attach($team, ['role' => 'owner']);

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get('/v5')
        ->assertSuccessful()
        ->assertSee('Unavailable')
        ->assertSee('Flux socket was not found.');
});
