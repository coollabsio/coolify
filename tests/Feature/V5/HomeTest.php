<?php

use App\Http\Kernel;
use App\Http\Middleware\CheckForcePasswordReset;
use App\Http\Middleware\DecideWhatToDoWithUser;
use App\Http\Middleware\V5\EnsureCurrentTeam;
use App\Http\Middleware\V5\HandleInertiaRequests;
use App\Models\Team;
use App\Models\User;
use App\Services\Flux\FluxHealth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Mockery\MockInterface;

beforeEach(function () {
    Config::set('app.maintenance.store', 'array');
    Config::set('cache.default', 'array');

    Schema::dropIfExists('v5_projects');
    Schema::dropIfExists('team_user');
    Schema::dropIfExists('teams');
    Schema::dropIfExists('users');
});

it('registers the v5 home route', function () {
    expect(Route::has('v5.home'))->toBeTrue();
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

it('creates v5 project tables in the shared database', function () {
    createSharedUserAndTeamTables();

    $migration = include database_path('migrations/2026_06_04_050157_create_v5_projects_table.php');
    $migration->up();

    expect(Schema::hasTable('v5_projects'))->toBeTrue()
        ->and(Schema::hasColumns('v5_projects', [
            'id',
            'team_id',
            'created_by_user_id',
            'name',
            'description',
            'created_at',
            'updated_at',
        ]))->toBeTrue();
});

it('includes v5 project tables in the dev testing schema', function () {
    $schema = file_get_contents(database_path('schema/testing-schema.sql'));

    expect($schema)->toContain('CREATE TABLE IF NOT EXISTS "v5_projects"')
        ->and($schema)->toContain('"team_id" INTEGER NOT NULL')
        ->and($schema)->toContain('"created_by_user_id" INTEGER NOT NULL')
        ->and($schema)->toContain('2026_06_04_050157_create_v5_projects_table');
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
        ->assertSee('v5-app', false)
        ->assertSee('Home', false)
        ->assertSee('v5-ready', false)
        ->assertSee('Running')
        ->assertSee('Flux is running.')
        ->assertSee('coold-dev')
        ->assertSee('coold-dev-2')
        ->assertSee('100.64.0.1')
        ->assertSee('100.64.0.2')
        ->assertSee('builder')
        ->assertSee('builderCapacity')
        ->assertSee('V5 Shared Team')
        ->assertSee('Shared team details')
        ->assertSee('owner')
        ->assertSee($user->email);
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
        ->assertSee('Auto Selected Team')
        ->assertSee('admin');
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
