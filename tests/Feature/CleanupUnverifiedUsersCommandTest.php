<?php

use App\Models\Application;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\Subscription;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('constants.coolify.self_hosted', false);
});

test('it previews eligible unverified users without deleting them', function () {
    $user = User::factory()->unverified()->create([
        'email' => 'unverified@example.com',
    ]);

    $this->artisan('cloud:cleanup-unverified-users')
        ->expectsOutput('Found 1 unverified user eligible for deletion.')
        ->expectsOutput('Dry run only. Use --yes to delete eligible users.')
        ->assertSuccessful();

    $this->assertModelExists($user);
});

test('it reports dry run progress', function () {
    $users = User::factory()->unverified()->count(2)->create();

    $exitCode = Artisan::call('cloud:cleanup-unverified-users');

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toContain('Checking eligible users: 2/2');

    $users->each(fn (User $user) => $this->assertModelExists($user));
});

test('it deletes eligible unverified users with the yes option', function () {
    $user = User::factory()->unverified()->create([
        'email' => 'unverified@example.com',
    ]);

    $this->artisan('cloud:cleanup-unverified-users', ['--yes' => true])
        ->expectsOutput('Deleted 1 unverified user.')
        ->assertSuccessful();

    $this->assertModelMissing($user);
});

test('it reports deletion progress', function () {
    User::factory()->unverified()->count(2)->create();

    $exitCode = Artisan::call('cloud:cleanup-unverified-users', ['--yes' => true]);

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toContain('Deleting eligible users: 2/2');
});

test('it keeps verified users', function () {
    $user = User::factory()->create([
        'email' => 'verified@example.com',
    ]);

    $this->artisan('cloud:cleanup-unverified-users', ['--yes' => true])
        ->expectsOutput('Deleted 0 unverified users.')
        ->assertSuccessful();

    $this->assertModelExists($user);
});

test('it keeps unverified users with a Stripe subscription record', function () {
    $user = User::factory()->unverified()->create([
        'email' => 'subscribed@example.com',
    ]);

    Subscription::create([
        'team_id' => $user->teams()->firstOrFail()->id,
        'stripe_invoice_paid' => false,
    ]);

    $this->artisan('cloud:cleanup-unverified-users', ['--yes' => true])
        ->expectsOutput('Deleted 0 unverified users.')
        ->assertSuccessful();

    $this->assertModelExists($user);
});

test('it keeps unverified users with defined resources', function () {
    $user = User::factory()->unverified()->create([
        'email' => 'resource-owner@example.com',
    ]);

    $project = Project::factory()->create([
        'team_id' => $user->teams()->firstOrFail()->id,
    ]);
    $environment = Environment::factory()->create([
        'project_id' => $project->id,
    ]);
    Application::factory()->create([
        'environment_id' => $environment->id,
    ]);

    $this->artisan('cloud:cleanup-unverified-users', ['--yes' => true])
        ->expectsOutput('Deleted 0 unverified users.')
        ->assertSuccessful();

    $this->assertModelExists($user);
});

test('it keeps unverified users with servers', function () {
    $user = User::factory()->unverified()->create([
        'email' => 'server-owner@example.com',
    ]);

    Server::factory()->create([
        'team_id' => $user->teams()->firstOrFail()->id,
    ]);

    $this->artisan('cloud:cleanup-unverified-users', ['--yes' => true])
        ->expectsOutput('Deleted 0 unverified users.')
        ->assertSuccessful();

    $this->assertModelExists($user);
});

test('it keeps unverified root team members', function () {
    $user = User::factory()->unverified()->create([
        'email' => 'root-member@example.com',
    ]);
    $rootTeam = Team::factory()->create([
        'id' => 0,
        'name' => 'Root Team',
    ]);
    $rootTeam->members()->attach($user->id, ['role' => 'admin']);

    $this->artisan('cloud:cleanup-unverified-users', ['--yes' => true])
        ->expectsOutput('Deleted 0 unverified users.')
        ->assertSuccessful();

    $this->assertModelExists($user);
});

test('it only runs on Coolify Cloud', function () {
    config()->set('constants.coolify.self_hosted', true);

    $this->artisan('cloud:cleanup-unverified-users')
        ->expectsOutput('This command can only be run on Coolify Cloud.')
        ->assertFailed();
});
