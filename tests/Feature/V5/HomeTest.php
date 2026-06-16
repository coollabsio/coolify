<?php

use App\Http\Kernel;
use App\Http\Middleware\CheckForcePasswordReset;
use App\Http\Middleware\DecideWhatToDoWithUser;
use App\Http\Middleware\V5\EnsureCurrentTeam;
use App\Http\Middleware\V5\HandleInertiaRequests;
use App\Models\Environment;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use App\Models\V5\Cluster;
use App\Models\V5\Server as V5Server;
use App\Services\Flux\FluxHealth;
use Database\Seeders\V5DevLimaSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Mockery\MockInterface;

beforeEach(function () {
    Config::set('app.maintenance.store', 'array');
    Config::set('cache.default', 'array');

    Schema::dropIfExists('v5_servers');
    Schema::dropIfExists('v5_clusters');
    Schema::dropIfExists('private_keys');
    Schema::dropIfExists('team_user');
    Schema::dropIfExists('teams');
    Schema::dropIfExists('users');
});

it('registers the v5 home route', function () {
    expect(Route::has('v5.home'))->toBeTrue()
        ->and(Route::has('v5.selection.update'))->toBeTrue();
});

it('uses separated v5 middleware groups', function () {
    $kernel = app(Kernel::class);
    $reflection = new ReflectionClass($kernel);
    $property = $reflection->getProperty('middlewareGroups');
    $property->setAccessible(true);
    $groups = $property->getValue($kernel);

    expect($groups)->toHaveKey('v5.web')
        ->and($groups)->toHaveKey('v5.authenticated')
        ->and($groups['v5.web'])->toContain(HandleInertiaRequests::class)
        ->and($groups['v5.web'])->not->toContain(CheckForcePasswordReset::class)
        ->and($groups['v5.web'])->not->toContain(DecideWhatToDoWithUser::class)
        ->and($groups['v5.authenticated'])->toContain('auth')
        ->and($groups['v5.authenticated'])->toContain('verified')
        ->and($groups['v5.authenticated'])->toContain(EnsureCurrentTeam::class);
});

it('reuses existing projects instead of creating v5 projects', function () {
    expect(file_exists(database_path('migrations/2026_06_04_050157_v5_create_projects_table.php')))->toBeFalse()
        ->and(file_exists(app_path('Models/V5/Project.php')))->toBeFalse();
});

it('creates v5 cluster tables and lets each server belong to one cluster', function () {
    createSharedUserAndTeamTables();
    Schema::dropIfExists('v5_servers');
    Schema::dropIfExists('v5_clusters');

    $clusterMigration = include database_path('migrations/2026_06_16_130649_v5_create_clusters_table.php');
    $clusterMigration->up();

    expect(Schema::hasTable('v5_clusters'))->toBeTrue()
        ->and(Schema::hasColumns('v5_clusters', [
            'id',
            'team_id',
            'created_by_user_id',
            'name',
            'description',
            'created_at',
            'updated_at',
        ]))->toBeTrue();

    $serverMigration = include database_path('migrations/2026_06_16_130650_v5_create_servers_table.php');
    $serverMigration->up();

    expect(Schema::hasColumn('v5_servers', 'cluster_id'))->toBeTrue();
});

it('creates v5 server tables in the shared database', function () {
    createSharedUserAndTeamTables();

    Schema::dropIfExists('v5_servers');
    Schema::dropIfExists('v5_clusters');

    $clusterMigration = include database_path('migrations/2026_06_16_130649_v5_create_clusters_table.php');
    $clusterMigration->up();

    $migration = include database_path('migrations/2026_06_16_130650_v5_create_servers_table.php');
    $migration->up();

    expect(Schema::hasTable('v5_servers'))->toBeTrue()
        ->and(Schema::hasColumns('v5_servers', [
            'id',
            'team_id',
            'cluster_id',
            'created_by_user_id',
            'private_key_id',
            'name',
            'host',
            'ssh_user',
            'ssh_port',
            'status',
            'capabilities',
            'builder_enabled',
            'builder_capacity',
            'last_bootstrapped_at',
            'created_at',
            'updated_at',
        ]))->toBeTrue();
});

it('includes v5 tables in the dev testing schema', function () {
    $schema = file_get_contents(database_path('schema/testing-schema.sql'));

    expect($schema)->toContain('"team_id" INTEGER NOT NULL')
        ->and($schema)->toContain('"created_by_user_id" INTEGER NOT NULL')
        ->and($schema)->not->toContain('CREATE TABLE IF NOT EXISTS "v5_projects"')
        ->and($schema)->not->toContain('2026_06_04_050157_v5_create_projects_table')
        ->and($schema)->toContain('CREATE TABLE IF NOT EXISTS "v5_servers"')
        ->and($schema)->toContain('"cluster_id" INTEGER')
        ->and($schema)->toContain('CREATE TABLE IF NOT EXISTS "v5_clusters"')
        ->and($schema)->toContain('"private_key_id" INTEGER')
        ->and($schema)->toContain('2026_06_16_130650_v5_create_servers_table')
        ->and($schema)->toContain('2026_06_16_130649_v5_create_clusters_table')
        ->and($schema)->not->toContain('2026_06_16_131229_add_cluster_id_to_v5_servers_table')
        ->and($schema)->not->toContain('2026_06_16_132000_make_v5_server_private_key_nullable')
        ->and($schema)->not->toContain('v5_hosts');
});

it('redirects guests to the shared login', function () {
    $this->get('/v5')
        ->assertRedirect('/login');
});

it('serves the v5 inertia shell', function () {
    $this->withoutVite();
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
        ->assertSee('v5-app', false)
        ->assertSee('Home', false)
        ->assertDontSee('v5-ready', false)
        ->assertDontSee('This page is served from Laravel through Inertia and React')
        ->assertDontSee('Bootstrap server')
        ->assertDontSee('privateKeys', false)
        ->assertSee('Running')
        ->assertSee('Flux is running.')
        ->assertSee('"clusters":[]', false)
        ->assertDontSee('cooldServers', false)
        ->assertDontSee('coold-dev')
        ->assertDontSee('100.64.0.1')
        ->assertDontSee('Current team')
        ->assertDontSee('Your teams')
        ->assertDontSee('currentTeam', false)
        ->assertDontSee('teams', false)
        ->assertDontSee('V5 Shared Team')
        ->assertDontSee('Shared team details');
});

it('shows v5 clusters with their servers on the inertia shell', function () {
    $this->withoutVite();
    fakeFluxHealth();
    createSharedUserAndTeamTables();

    [$user, $team] = createV5UserWithTeam();
    $privateKey = createV5PrivateKey($team, 'Lima Key');
    $cluster = Cluster::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'name' => 'Development-Lima',
        'description' => 'Local Lima development cluster managed by scripts/dev.sh.',
    ]);
    V5Server::query()->create([
        'team_id' => $team->id,
        'cluster_id' => $cluster->id,
        'created_by_user_id' => $user->id,
        'private_key_id' => $privateKey->id,
        'name' => 'coold-dev',
        'host' => 'lima-coold-dev',
        'ssh_user' => 'developer',
        'ssh_port' => 22,
        'status' => 'installed',
        'capabilities' => ['coold', 'builder'],
        'builder_enabled' => true,
        'builder_capacity' => 2,
        'last_bootstrapped_at' => now(),
    ]);

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get('/v5')
        ->assertSuccessful()
        ->assertSee('"clusters":[', false)
        ->assertSee('"name":"Development-Lima"', false)
        ->assertSee('"serversCount":1', false)
        ->assertSee('"name":"coold-dev"', false)
        ->assertSee('"host":"lima-coold-dev"', false);
});

it('shares existing projects and environments with the v5 home page', function () {
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

it('defines the v5 home page as a shadcn styled canvas shell', function () {
    $homePage = file_get_contents(resource_path('js/v5/Pages/Home.jsx'));
    $navbarPath = resource_path('js/v5/components/app-navbar.jsx');

    expect(file_exists($navbarPath))->toBeTrue();

    $navbar = file_get_contents($navbarPath);

    expect($homePage)
        ->toContain('Magic')
        ->toContain("import { AppNavbar } from '@/components/app-navbar';")
        ->not->toContain("import { csrfToken } from '@/lib/csrf';")
        ->not->toContain('function csrfToken()')
        ->toContain('<AppNavbar')
        ->toContain('bg-background text-foreground')
        ->toContain('h-dvh overflow-hidden bg-background text-foreground')
        ->toContain('flex h-full min-h-0 items-center justify-center overflow-hidden px-6 pt-16')
        ->not->toContain('<header')
        ->not->toContain('h-[calc(100dvh-4rem)]')
        ->not->toContain('flex h-dvh flex-col overflow-hidden bg-background text-foreground')
        ->toContain('This is where the magic happens.')
        ->not->toContain("import { Select, SelectContent, SelectGroup, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';")
        ->not->toContain("fetch('/v5/selection'")
        ->not->toContain("'X-CSRF-TOKEN': csrfToken()");

    expect($navbar)
        ->toContain("import { csrfToken } from '@/lib/csrf';")
        ->not->toContain('function csrfToken()')
        ->toContain('export function AppNavbar')
        ->toContain('/coolify-logo.svg')
        ->toContain('className="fixed inset-x-0 top-0 z-40 border-b border-border bg-background"')
        ->not->toContain('className="sticky top-0 z-40 shrink-0 border-b border-border bg-background"')
        ->toContain('bg-muted/40')
        ->toContain('text-muted-foreground')
        ->toContain('SelectGroup')
        ->toContain('variant="ghost"')
        ->toContain('className="max-w-[10rem]"')
        ->toContain('position="popper"')
        ->toContain('sideOffset={4}')
        ->toContain('Select a project')
        ->toContain('Select an environment')
        ->toContain("fetch('/v5/selection'")
        ->toContain("'X-CSRF-TOKEN': csrfToken()")
        ->not->toContain("import { Button } from '@/components/ui/button';")
        ->not->toContain('<Button')
        ->not->toContain("import { Separator } from '@/components/ui/separator';")
        ->not->toContain('<Separator')
        ->not->toContain('min-h-[calc(100vh-4rem)]')
        ->not->toContain('py-10')
        ->not->toContain('bg-background/95')
        ->not->toContain('backdrop-blur')
        ->not->toContain('supports-[backdrop-filter]')
        ->not->toContain('bg-coolgray')
        ->not->toContain('border-coolgray')
        ->not->toContain('text-warning')
        ->not->toContain('className="w-[12rem]"')
        ->not->toContain('<h1>Coolify v5</h1>')
        ->not->toContain('<h2 id="clusters-heading">Clusters</h2>');
});

it('defines a ghost variant for compact v5 select triggers', function () {
    $select = file_get_contents(resource_path('js/v5/components/ui/select.jsx'));

    expect($select)
        ->toContain('variant = "default"')
        ->toContain('variant === "default"')
        ->toContain('variant === "ghost"')
        ->toContain('border-transparent')
        ->toContain('h-auto')
        ->toContain('text-sm');
});

it('uses the requested shadcn preset configuration for v5', function () {
    $components = json_decode(file_get_contents(base_path('components.json')), true);
    $css = file_get_contents(resource_path('css/v5/app.css'));

    expect($components['style'])
        ->toBe('radix-lyra')
        ->and($components['iconLibrary'])->toBe('phosphor')
        ->and($components['tailwind']['css'])->toBe('resources/css/v5/app.css')
        ->and($components['tailwind']['baseColor'])->toBe('zinc')
        ->and($css)->toContain('@import "@fontsource-variable/geist";')
        ->and($css)->toContain('--foreground: oklch(0.141 0.005 285.823);')
        ->and($css)->toContain('--background: #101010;')
        ->and($css)->toContain('button:not(:disabled)');
});

it('selects a shared team when the session has no current team', function () {
    $this->withoutVite();
    fakeFluxHealth();
    createSharedUserAndTeamTables();

    $user = User::withoutEvents(fn () => User::query()->create([
        'name' => 'Grace Hopper',
        'email' => 'grace@example.com',
        'email_verified_at' => now(),
        'password' => 'password',
    ]));
    $team = Team::withoutEvents(fn () => Team::query()->create([
        'name' => 'Auto Selected Team',
        'description' => null,
        'personal_team' => false,
        'show_boarding' => false,
    ]));
    $user->teams()->attach($team, ['role' => 'admin']);

    $this
        ->actingAs($user)
        ->get('/v5')
        ->assertSuccessful()
        ->assertSessionHas('currentTeam')
        ->assertDontSee('Auto Selected Team')
        ->assertDontSee('admin');
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
            ->assertSee("src=\"http://{$viteHost}:5173/resources/js/v5/app.jsx\"", false)
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

it('does not include coolify version controls on the v5 home page', function () {
    $homePage = file_get_contents(resource_path('js/v5/Pages/Home.jsx'));

    expect($homePage)
        ->not->toContain('Check coolify version')
        ->not->toContain('/v5/coolify/version')
        ->not->toContain('Installed version:');
});

it('renders flux status as a compact summary', function () {
    $navbar = file_get_contents(resource_path('js/v5/components/app-navbar.jsx'));

    expect($navbar)
        ->toContain('Flux: {flux?.label ??')
        ->toContain('title={flux?.socket ?? flux?.message}')
        ->toContain('{clusters.length} clusters')
        ->not->toContain('<h2 id="flux-status-heading">Flux status</h2>')
        ->not->toContain('<p>{flux.message}</p>')
        ->not->toContain('Socket: {flux.socket}');
});

it('checks the installed coolify version', function () {
    createSharedUserAndTeamTables();
    [$user, $team] = createV5UserWithTeam();

    Process::fake([
        '*' => Process::result(output: 'coolify nightly-20260616', exitCode: 0),
    ]);

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->getJson('/v5/coolify/version')
        ->assertSuccessful()
        ->assertJson([
            'available' => true,
            'label' => 'Installed',
            'version' => 'coolify nightly-20260616',
            'message' => 'Installed version: coolify nightly-20260616.',
            'binary' => '/usr/local/bin/coolify',
        ]);
});

it('shows when coolify version check fails', function () {
    createSharedUserAndTeamTables();
    [$user, $team] = createV5UserWithTeam();

    Process::fake([
        '*' => Process::result(errorOutput: 'not found', exitCode: 127),
    ]);

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->getJson('/v5/coolify/version')
        ->assertSuccessful()
        ->assertJson([
            'available' => false,
            'label' => 'Unavailable',
            'version' => null,
            'message' => 'not found',
            'binary' => '/usr/local/bin/coolify',
        ]);
});

it('rejects coolify bootstrap when the selected private key is not owned by the current team', function () {
    createSharedUserAndTeamTables();
    [$user, $team] = createV5UserWithTeam();
    $otherTeam = Team::withoutEvents(fn () => Team::query()->create([
        'name' => 'Other Team',
        'description' => null,
        'personal_team' => false,
        'show_boarding' => false,
    ]));
    $privateKey = createV5PrivateKey($otherTeam, 'Other Key');

    Process::fake();

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->postJson('/v5/coolify/bootstrap', [
            'host' => '192.0.2.10',
            'ssh_user' => 'root',
            'ssh_port' => 22,
            'private_key_uuid' => $privateKey->uuid,
        ])
        ->assertForbidden()
        ->assertJson([
            'successful' => false,
            'label' => 'Private key unavailable',
        ]);

    Process::assertDidntRun(fn () => true);
});

it('runs coolify bootstrap from dynamic UI input and a selected team private key', function () {
    createSharedUserAndTeamTables();
    [$user, $team] = createV5UserWithTeam();
    $privateKey = createV5PrivateKey($team, 'Bootstrap Key');

    Config::set('coold.coolify_cli_bin', '/usr/local/bin/coolify');
    Config::set('coold.dev_builder_capacity', 2);

    Process::fake([
        '*' => Process::result(output: 'Bootstrapping mesh...', exitCode: 0),
    ]);

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->postJson('/v5/coolify/bootstrap', [
            'host' => '192.0.2.10',
            'ssh_user' => 'ubuntu',
            'ssh_port' => 2222,
            'private_key_uuid' => $privateKey->uuid,
            'wg_listen_port' => 51821,
            'wg_endpoint' => 'example.test:51821',
            'enable_builder' => true,
            'builder_capacity' => 3,
        ])
        ->assertSuccessful()
        ->assertJson([
            'successful' => true,
            'label' => 'Bootstrap finished',
            'message' => 'coolify init bootstrap completed successfully.',
            'output' => 'Bootstrapping mesh...',
            'exitCode' => 0,
        ]);

    $this
        ->actingAs($user)
        ->withSession(['currentTeam' => $team])
        ->get('/v5')
        ->assertSuccessful()
        ->assertDontSee('"host":"192.0.2.10"', false)
        ->assertDontSee('"capabilities":["coold","builder"]', false);

    expect(V5Server::query()->where('host', '192.0.2.10')->where('status', 'installed')->exists())->toBeTrue();

    $sshKeyPath = null;

    Process::assertRan(function ($process) use (&$sshKeyPath) {
        preg_match("/'--ssh-key' '([^']+)'/", $process->command, $matches);
        $sshKeyPath = $matches[1] ?? null;

        return $process->timeout === 300
            && str_contains($process->command, "'/usr/local/bin/coolify' 'init' 'bootstrap'")
            && str_contains($process->command, "'--nodes' '192.0.2.10:2222'")
            && str_contains($process->command, "'--ssh-user' 'ubuntu'")
            && str_contains($process->command, "'--wg-listen-port-overrides' '192.0.2.10:2222=51821'")
            && str_contains($process->command, "'--wg-endpoint-overrides' '192.0.2.10:2222=example.test:51821'")
            && str_contains($process->command, "'--coold-version' 'nightly'")
            && str_contains($process->command, "'--corrosion-version' 'v1.0.0'")
            && str_contains($process->command, "'--enable-builder'")
            && str_contains($process->command, "'--builder-capacity' '3'")
            && str_contains($process->command, "'--yes'")
            && ! str_contains($process->command, 'COOLIFY_CLI_NODES');
    });

    expect($sshKeyPath)->not->toBeNull()
        ->and(file_exists($sshKeyPath))->toBeFalse();
});

it('syncs dev Lima VMs into v5 clusters and servers', function () {
    createSharedUserAndTeamTables();
    [$user, $team] = createV5UserWithTeam();
    $privateKey = createV5PrivateKey($team, 'Dev Lima Key');

    $exitCode = Artisan::call('v5:sync-dev-lima-servers', [
        '--team-id' => $team->id,
        '--user-id' => $user->id,
        '--private-key-id' => $privateKey->id,
        '--cluster' => 'Development-Lima',
        '--builder-capacity' => 2,
        '--server' => [
            'coold-dev|lima-coold-dev|developer|22',
            'coold-dev-2|lima-coold-dev-2|developer|22',
        ],
    ]);

    expect($exitCode)->toBe(0)
        ->and(Cluster::query()->where('name', 'Development-Lima')->count())->toBe(1)
        ->and(V5Server::query()->where('name', 'coold-dev')->where('host', 'lima-coold-dev')->exists())->toBeTrue()
        ->and(V5Server::query()->where('name', 'coold-dev-2')->where('host', 'lima-coold-dev-2')->exists())->toBeTrue();
});

it('seeds dev Lima VMs into v5 clusters and servers idempotently', function () {
    createSharedUserAndTeamTables();
    [$user, $team] = createV5UserWithTeam();
    createV5PrivateKey($team, 'Dev Lima Key');
    config()->set('coold.dev_builder_capacity', 2);

    (new V5DevLimaSeeder)->run();
    (new V5DevLimaSeeder)->run();

    $cluster = Cluster::query()->where('name', 'Development-Lima')->sole();

    expect($cluster->team_id)->toBe($team->id)
        ->and($cluster->created_by_user_id)->toBe($user->id)
        ->and($cluster->description)->toBe('Local Lima development cluster managed by scripts/dev.sh.')
        ->and(V5Server::query()->count())->toBe(2)
        ->and(V5Server::query()->where('name', 'coold-dev')->where('host', 'lima-coold-dev')->where('ssh_user', get_current_user())->where('ssh_port', 22)->exists())->toBeTrue()
        ->and(V5Server::query()->where('name', 'coold-dev-2')->where('host', 'lima-coold-dev-2')->where('ssh_user', get_current_user())->where('ssh_port', 22)->exists())->toBeTrue()
        ->and(V5Server::query()->where('status', 'installed')->count())->toBe(2)
        ->and(V5Server::query()->where('builder_enabled', true)->where('builder_capacity', 2)->count())->toBe(2)
        ->and(V5Server::query()->where('cluster_id', $cluster->id)->count())->toBe(2);
});

function fakeFluxHealth(bool $available = true, string $message = 'Flux is running.'): void
{
    app()->instance(FluxHealth::class, Mockery::mock(FluxHealth::class, function (MockInterface $mock) use ($available, $message) {
        $mock->shouldReceive('check')
            ->once()
            ->andReturn([
                'available' => $available,
                'label' => $available ? 'Running' : 'Unavailable',
                'message' => $message,
                'socket' => '/run/coolify/flux.sock',
            ]);
    }));
}

function createSharedUserAndTeamTables(): void
{
    Schema::create('users', function ($table) {
        $table->id();
        $table->string('name')->default('Anonymous');
        $table->string('email');
        $table->timestamp('email_verified_at')->nullable();
        $table->string('password')->nullable();
        $table->rememberToken();
        $table->timestamps();
    });

    Schema::create('teams', function ($table) {
        $table->id();
        $table->string('name');
        $table->string('description')->nullable();
        $table->boolean('personal_team')->default(false);
        $table->boolean('show_boarding')->default(false);
        $table->timestamps();
    });

    Schema::create('private_keys', function ($table) {
        $table->id();
        $table->string('uuid')->unique();
        $table->string('name');
        $table->string('description')->nullable();
        $table->longText('private_key');
        $table->string('fingerprint')->nullable();
        $table->boolean('is_git_related')->default(false);
        $table->foreignId('team_id');
        $table->timestamps();
    });

    Schema::create('projects', function ($table) {
        $table->id();
        $table->string('uuid');
        $table->string('name');
        $table->text('description')->nullable();
        $table->foreignId('team_id');
        $table->timestamps();
    });

    Schema::create('environments', function ($table) {
        $table->id();
        $table->string('name');
        $table->foreignId('project_id');
        $table->timestamps();
        $table->text('description')->nullable();
        $table->string('uuid');
    });

    Schema::create('v5_clusters', function ($table) {
        $table->id();
        $table->foreignId('team_id');
        $table->foreignId('created_by_user_id');
        $table->string('name');
        $table->text('description')->nullable();
        $table->timestamps();
    });

    Schema::create('v5_servers', function ($table) {
        $table->id();
        $table->foreignId('team_id');
        $table->foreignId('cluster_id')->nullable();
        $table->foreignId('created_by_user_id');
        $table->foreignId('private_key_id')->nullable();
        $table->string('name');
        $table->string('host');
        $table->string('ssh_user');
        $table->unsignedInteger('ssh_port');
        $table->string('status')->default('installed');
        $table->json('capabilities')->nullable();
        $table->boolean('builder_enabled')->default(false);
        $table->unsignedInteger('builder_capacity')->default(0);
        $table->timestamp('last_bootstrapped_at')->nullable();
        $table->timestamps();
    });

    Schema::create('team_user', function ($table) {
        $table->id();
        $table->foreignId('team_id');
        $table->foreignId('user_id');
        $table->string('role')->default('member');
        $table->timestamps();

        $table->unique(['team_id', 'user_id']);
    });
}

/**
 * @return array{0: Project, 1: Environment}
 */
function createV5ProjectWithEnvironment(Team $team, string $projectName, string $environmentName): array
{
    $project = Project::withoutEvents(fn () => Project::query()->forceCreate([
        'uuid' => str($projectName)->slug().'-uuid',
        'name' => $projectName,
        'description' => null,
        'team_id' => $team->id,
    ]));

    $environment = Environment::withoutEvents(fn () => Environment::query()->forceCreate([
        'uuid' => str($environmentName)->slug().'-uuid',
        'name' => $environmentName,
        'description' => null,
        'project_id' => $project->id,
    ]));

    return [$project, $environment];
}

function createV5PrivateKey(Team $team, string $name): PrivateKey
{
    return PrivateKey::withoutEvents(fn () => PrivateKey::query()->forceCreate([
        'uuid' => str($name)->slug().'-uuid',
        'name' => $name,
        'description' => null,
        'private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\ntest-key\n-----END OPENSSH PRIVATE KEY-----\n",
        'fingerprint' => str($name)->slug()->toString(),
        'is_git_related' => false,
        'team_id' => $team->id,
    ]));
}

/**
 * @return array{0: User, 1: Team}
 */
function createV5UserWithTeam(): array
{
    $user = User::withoutEvents(fn () => User::query()->create([
        'name' => 'Margaret Hamilton',
        'email' => 'margaret@example.com',
        'email_verified_at' => now(),
        'password' => 'password',
    ]));
    $team = Team::withoutEvents(fn () => Team::query()->create([
        'name' => 'V5 Tooling Team',
        'description' => null,
        'personal_team' => false,
        'show_boarding' => false,
    ]));
    $user->teams()->attach($team, ['role' => 'owner']);

    return [$user, $team];
}
