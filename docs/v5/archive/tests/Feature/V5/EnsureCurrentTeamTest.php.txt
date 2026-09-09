<?php

use App\Http\Middleware\V5\EnsureCurrentTeam;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    Config::set('cache.default', 'array');

    Schema::dropIfExists('team_user');
    Schema::dropIfExists('teams');
    Schema::dropIfExists('users');

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
});

function createEnsureCurrentTeamUser(string $email = 'margaret@example.com'): User
{
    return User::withoutEvents(fn () => User::query()->create([
        'name' => 'Margaret Hamilton',
        'email' => $email,
        'email_verified_at' => now(),
        'password' => 'password',
    ]));
}

function createEnsureCurrentTeamTeam(string $name = 'V5 Tooling Team'): Team
{
    return Team::withoutEvents(fn () => Team::query()->create([
        'name' => $name,
        'description' => 'The team that ships v5.',
        'personal_team' => false,
        'show_boarding' => false,
    ]));
}

/**
 * Runs the middleware against a request the way the v5.authenticated group
 * would: an authenticated user with an active session store.
 *
 * @return array{0: Response, 1: Request}
 */
function runEnsureCurrentTeam(?User $user): array
{
    $request = Request::create('/v5', 'GET');
    $request->setLaravelSession(app('session.store'));
    $request->setUserResolver(fn () => $user);

    $response = (new EnsureCurrentTeam)->handle($request, fn () => response()->noContent());

    return [$response, $request];
}

it('aborts 403 when the user belongs to no team', function () {
    $user = createEnsureCurrentTeamUser();

    expect(fn () => runEnsureCurrentTeam($user))
        ->toThrow(fn (HttpException $exception) => expect($exception->getStatusCode())->toBe(403));
});

it('falls back to first team when the session team is no longer a member', function () {
    $user = createEnsureCurrentTeamUser();
    $memberTeam = createEnsureCurrentTeamTeam('Member Team');
    $foreignTeam = createEnsureCurrentTeamTeam('Foreign Team');
    $user->teams()->attach($memberTeam, ['role' => 'owner']);

    session(['currentTeam' => $foreignTeam]);

    [, $request] = runEnsureCurrentTeam($user);

    expect($request->attributes->get('v5.currentTeam')->id)->toBe($memberTeam->id)
        ->and(session('currentTeam'))->toBeInstanceOf(Team::class)
        ->and(session('currentTeam')->id)->toBe($memberTeam->id)
        ->and(session('currentTeam')->name)->toBe('Member Team');
});

it('stores the full team model so v4 session reads keep working', function () {
    $user = createEnsureCurrentTeamUser();
    $team = createEnsureCurrentTeamTeam();
    $user->teams()->attach($team, ['role' => 'owner']);

    runEnsureCurrentTeam($user);

    $sessionTeam = session('currentTeam');

    // v4 reads columns beyond id/name/description/personal_team off the
    // session team (e.g. show_boarding, custom limits), so the stored model
    // must not be a partial select.
    expect($sessionTeam)->toBeInstanceOf(Team::class)
        ->and($sessionTeam->getAttributes())->toHaveKeys([
            'id',
            'name',
            'description',
            'personal_team',
            'show_boarding',
            'created_at',
            'updated_at',
        ])
        ->and($sessionTeam->created_at)->not->toBeNull()
        ->and((bool) $sessionTeam->show_boarding)->toBeFalse();
});

it('does not rewrite the session when the team is unchanged', function () {
    $user = createEnsureCurrentTeamUser();
    $team = createEnsureCurrentTeamTeam();
    $user->teams()->attach($team, ['role' => 'owner']);

    // Prime the session with the same team but a sentinel attribute; if the
    // middleware skips the redundant write, the sentinel survives the request.
    $sessionTeam = Team::query()->findOrFail($team->id);
    $sessionTeam->name = 'Session Sentinel';
    session(['currentTeam' => $sessionTeam]);

    [, $request] = runEnsureCurrentTeam($user);

    expect($request->attributes->get('v5.currentTeam'))->toBeInstanceOf(Team::class)
        ->and($request->attributes->get('v5.currentTeam')->id)->toBe($team->id)
        ->and($request->attributes->get('v5.currentTeam')->name)->toBe('V5 Tooling Team')
        ->and(session('currentTeam'))->toBe($sessionTeam)
        ->and(session('currentTeam')->name)->toBe('Session Sentinel');
});
