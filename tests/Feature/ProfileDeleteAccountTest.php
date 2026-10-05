<?php

use App\Actions\User\DeleteUserAccount;
use App\Enums\GithubRunnerStatus;
use App\Jobs\CleanupGithubRunnerJob;
use App\Livewire\Profile\Index as ProfileIndex;
use App\Models\GithubApp;
use App\Models\GithubRunnerConfig;
use App\Models\GithubRunnerExecution;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\Subscription;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Stripe\Service\SubscriptionService;
use Stripe\StripeClient;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    $this->rootTeam = Team::factory()->create(['id' => 0, 'name' => 'Root Team']);
    $this->rootUser = User::factory()->create(['id' => 0]);
});

function deleteAccountUser(): User
{
    $user = User::factory()->create(['name' => 'Delete Me', 'password' => bcrypt('password')]);
    test()->actingAs($user);

    return $user;
}

it('deletes the account and the personal team, then logs the user out', function () {
    $user = deleteAccountUser();
    $personalTeam = $user->teams()->first();

    Livewire::test(ProfileIndex::class)
        ->call('deleteAccount', 'password')
        ->assertRedirect(route('login'));

    expect(User::find($user->id))->toBeNull()
        ->and(Team::find($personalTeam->id))->toBeNull()
        ->and(DB::table('team_user')->where('user_id', $user->id)->exists())->toBeFalse()
        ->and(auth()->check())->toBeFalse();
});

it('does not delete the account with a wrong password', function () {
    $user = deleteAccountUser();

    Livewire::test(ProfileIndex::class)
        ->call('deleteAccount', 'wrong-password')
        ->assertHasErrors('password');

    expect(User::find($user->id))->not->toBeNull();
});

it('never deletes the root user', function () {
    expect(app(DeleteUserAccount::class)->blockers($this->rootUser))
        ->toBe(['The root user cannot be deleted.']);

    expect(fn () => app(DeleteUserAccount::class)->handle($this->rootUser))
        ->toThrow(RuntimeException::class, 'The root user cannot be deleted.');

    expect(User::find(0))->not->toBeNull();
});

it('blocks deletion while a single-member team still has projects', function () {
    $user = deleteAccountUser();
    $personalTeam = $user->teams()->first();
    Project::factory()->create(['team_id' => $personalTeam->id]);

    Livewire::test(ProfileIndex::class)
        ->assertSee('Delete all projects, servers, and Git sources')
        ->call('deleteAccount', 'password');

    expect(User::find($user->id))->not->toBeNull()
        ->and(Team::find($personalTeam->id))->not->toBeNull();
});

it('transfers team ownership to an admin and keeps the shared team', function () {
    $user = deleteAccountUser();
    $admin = User::factory()->create();
    $sharedTeam = Team::factory()->create();
    $sharedTeam->members()->attach($user->id, ['role' => 'owner']);
    $sharedTeam->members()->attach($admin->id, ['role' => 'admin']);

    app(DeleteUserAccount::class)->handle($user);

    expect(User::find($user->id))->toBeNull()
        ->and(Team::find($sharedTeam->id))->not->toBeNull()
        ->and($admin->roleInTeam($sharedTeam->id))->toBe('owner');
});

it('blocks deletion when a shared team has no other owner or admin', function () {
    $user = deleteAccountUser();
    $member = User::factory()->create();
    $sharedTeam = Team::factory()->create(['name' => 'Shared']);
    $sharedTeam->members()->attach($user->id, ['role' => 'owner']);
    $sharedTeam->members()->attach($member->id, ['role' => 'member']);

    expect(app(DeleteUserAccount::class)->blockers($user))
        ->toBe(['Make another member of the team "Shared" an admin or owner.']);
});

it('removes a member from other teams without changing those teams', function () {
    $owner = User::factory()->create();
    $user = deleteAccountUser();
    $sharedTeam = Team::factory()->create();
    $sharedTeam->members()->attach($owner->id, ['role' => 'owner']);
    $sharedTeam->members()->attach($user->id, ['role' => 'member']);
    $this->rootTeam->members()->attach($user->id, ['role' => 'member']);

    app(DeleteUserAccount::class)->handle($user);

    expect(User::find($user->id))->toBeNull()
        ->and($sharedTeam->members()->pluck('users.id')->all())->toBe([$owner->id])
        ->and($this->rootTeam->members()->pluck('users.id')->all())->toBe([$this->rootUser->id]);
});

it('cancels the subscription immediately before deleting the account on cloud', function () {
    config(['constants.coolify.self_hosted' => false]);
    $user = deleteAccountUser();
    $personalTeam = $user->teams()->first();
    $personalTeam->update(['name' => 'Paid']);
    Subscription::create([
        'team_id' => $personalTeam->id,
        'stripe_subscription_id' => 'sub_123',
        'stripe_customer_id' => 'cus_123',
        'stripe_invoice_paid' => true,
    ]);

    $stripe = Mockery::mock(StripeClient::class);
    $stripe->subscriptions = Mockery::mock(SubscriptionService::class);
    $stripe->subscriptions->shouldReceive('cancel')->once()->with('sub_123', [])->andReturn(Stripe\Subscription::constructFrom(['id' => 'sub_123', 'status' => 'canceled']));
    app()->instance(StripeClient::class, $stripe);

    Livewire::test(ProfileIndex::class)
        ->assertSee('will be cancelled immediately. This is required.')
        ->call('deleteAccount', 'password', [])
        ->assertRedirect(route('login'));

    expect(User::find($user->id))->toBeNull()
        ->and(Team::find($personalTeam->id))->toBeNull()
        ->and(Subscription::where('stripe_subscription_id', 'sub_123')->exists())->toBeFalse()
        ->and(Subscription::where('team_id', $personalTeam->id)->exists())->toBeFalse();
});

it('keeps the account and subscription when immediate cancellation fails', function () {
    config(['constants.coolify.self_hosted' => false]);
    $user = deleteAccountUser();
    $team = $user->teams()->first();
    $subscription = Subscription::create(['team_id' => $team->id, 'stripe_subscription_id' => 'sub_failed', 'stripe_invoice_paid' => true]);
    $stripe = Mockery::mock(StripeClient::class);
    $stripe->subscriptions = Mockery::mock(SubscriptionService::class);
    $stripe->subscriptions->shouldReceive('cancel')->once()->with('sub_failed', [])->andThrow(new RuntimeException('Stripe unavailable'));
    app()->instance(StripeClient::class, $stripe);

    expect(fn () => app(DeleteUserAccount::class)->handle($user))->toThrow(RuntimeException::class);
    expect(User::find($user->id))->not->toBeNull()
        ->and(Team::find($team->id))->not->toBeNull()
        ->and($subscription->fresh()->stripe_invoice_paid)->toBeTruthy();
});

it('does not cancel subscriptions before resolving account deletion blockers', function () {
    config(['constants.coolify.self_hosted' => false]);
    $user = deleteAccountUser();
    $team = $user->teams()->first();
    Project::factory()->create(['team_id' => $team->id]);
    $subscription = Subscription::create(['team_id' => $team->id, 'stripe_subscription_id' => 'sub_blocked', 'stripe_invoice_paid' => true]);
    $stripe = Mockery::mock(StripeClient::class);
    $stripe->subscriptions = Mockery::mock(SubscriptionService::class);
    $stripe->subscriptions->shouldNotReceive('cancel');
    app()->instance(StripeClient::class, $stripe);

    expect(fn () => app(DeleteUserAccount::class)->handle($user))->toThrow(RuntimeException::class);
    expect(User::find($user->id))->not->toBeNull()
        ->and($subscription->fresh()->stripe_invoice_paid)->toBeTruthy();
});

it('preserves subscriptions of other teams and teams where the user is not an owner', function (string $role) {
    config(['constants.coolify.self_hosted' => false]);
    $owner = User::factory()->create();
    $team = $owner->teams()->first();
    $user = deleteAccountUser();
    $team->members()->attach($user->id, ['role' => $role]);
    $subscription = Subscription::create(['team_id' => $team->id, 'stripe_subscription_id' => 'sub_other', 'stripe_invoice_paid' => true]);
    $stripe = Mockery::mock(StripeClient::class);
    $stripe->subscriptions = Mockery::mock(SubscriptionService::class);
    $stripe->subscriptions->shouldNotReceive('cancel');
    app()->instance(StripeClient::class, $stripe);

    app(DeleteUserAccount::class)->handle($user);
    expect(User::find($user->id))->toBeNull()
        ->and($subscription->fresh()->stripe_invoice_paid)->toBeTruthy();
})->with(['member', 'admin']);

it('does not cancel a subscription with an incorrect password', function () {
    config(['constants.coolify.self_hosted' => false]);
    $user = deleteAccountUser();
    $subscription = Subscription::create(['team_id' => $user->teams()->first()->id, 'stripe_subscription_id' => 'sub_password', 'stripe_invoice_paid' => true]);
    $stripe = Mockery::mock(StripeClient::class);
    $stripe->subscriptions = Mockery::mock(SubscriptionService::class);
    $stripe->subscriptions->shouldNotReceive('cancel');
    app()->instance(StripeClient::class, $stripe);

    Livewire::test(ProfileIndex::class)->call('deleteAccount', 'wrong-password')->assertHasErrors('password');
    expect(User::find($user->id))->not->toBeNull()
        ->and($subscription->fresh()->stripe_invoice_paid)->toBeTruthy();
});

it('cancels a past due subscription and preserves a transferred team', function () {
    config(['constants.coolify.self_hosted' => false]);
    $user = deleteAccountUser();
    $admin = User::factory()->create();
    $team = $user->teams()->first();
    $team->members()->attach($admin->id, ['role' => 'admin']);
    $subscription = Subscription::create(['team_id' => $team->id, 'stripe_subscription_id' => 'sub_past_due', 'stripe_invoice_paid' => false, 'stripe_past_due' => true]);
    $stripe = Mockery::mock(StripeClient::class);
    $stripe->subscriptions = Mockery::mock(SubscriptionService::class);
    $stripe->subscriptions->shouldReceive('cancel')->once()->with('sub_past_due', [])->andReturn(Stripe\Subscription::constructFrom(['id' => 'sub_past_due', 'status' => 'canceled']));
    app()->instance(StripeClient::class, $stripe);

    app(DeleteUserAccount::class)->handle($user);
    expect(User::find($user->id))->toBeNull()
        ->and(Team::find($team->id))->not->toBeNull()
        ->and($admin->roleInTeam($team->id))->toBe('owner')
        ->and($subscription->fresh()->stripe_subscription_id)->toBeNull()
        ->and($subscription->fresh()->stripe_past_due)->toBeFalsy();
});

it('does not cancel the root team subscription when another owner deletes their account', function () {
    config(['constants.coolify.self_hosted' => false]);
    $user = deleteAccountUser();
    $this->rootTeam->members()->attach($user->id, ['role' => 'owner']);
    $subscription = Subscription::create(['team_id' => 0, 'stripe_subscription_id' => 'sub_root', 'stripe_invoice_paid' => true]);
    $stripe = Mockery::mock(StripeClient::class);
    $stripe->subscriptions = Mockery::mock(SubscriptionService::class);
    $stripe->subscriptions->shouldNotReceive('cancel');
    app()->instance(StripeClient::class, $stripe);

    app(DeleteUserAccount::class)->handle($user);
    expect(User::find($user->id))->toBeNull()
        ->and($subscription->fresh()->stripe_invoice_paid)->toBeTruthy()
        ->and(Team::find(0))->not->toBeNull();
});

it('removes the data of single-member teams only when asked and keeps shared team data', function () {
    $user = deleteAccountUser();
    $personalTeam = $user->teams()->first();
    $personalProject = Project::factory()->create(['team_id' => $personalTeam->id]);
    $personalKey = PrivateKey::factory()->create(['team_id' => $personalTeam->id]);
    $personalServer = Server::factory()->create(['team_id' => $personalTeam->id, 'private_key_id' => $personalKey->id]);

    $admin = User::factory()->create();
    $sharedTeam = Team::factory()->create();
    $sharedTeam->members()->attach($user->id, ['role' => 'owner']);
    $sharedTeam->members()->attach($admin->id, ['role' => 'admin']);
    $sharedProject = Project::factory()->create(['team_id' => $sharedTeam->id]);
    $sharedKey = PrivateKey::factory()->create(['team_id' => $sharedTeam->id]);
    $sharedServer = Server::factory()->create(['team_id' => $sharedTeam->id, 'private_key_id' => $sharedKey->id]);

    expect(fn () => app(DeleteUserAccount::class)->handle($user))->toThrow(RuntimeException::class);
    expect(Project::find($personalProject->id))->not->toBeNull();

    app(DeleteUserAccount::class)->handle($user, removeTeamResources: true);

    expect(User::find($user->id))->toBeNull()
        ->and(Team::find($personalTeam->id))->toBeNull()
        ->and(Project::find($personalProject->id))->toBeNull()
        ->and(Server::withTrashed()->find($personalServer->id))->toBeNull()
        ->and(Project::find($sharedProject->id))->not->toBeNull()
        ->and(Server::find($sharedServer->id))->not->toBeNull()
        ->and($admin->roleInTeam($sharedTeam->id))->toBe('owner');
});

it('forgets busy github runners without cleanup instead of blocking the removal of team data', function () {
    Bus::fake();
    $user = deleteAccountUser();
    $team = $user->teams()->first();
    $privateKey = PrivateKey::factory()->create(['team_id' => $team->id]);
    $server = Server::factory()->create(['team_id' => $team->id, 'private_key_id' => $privateKey->id]);
    $githubApp = GithubApp::create([
        'name' => 'Runner App',
        'team_id' => $team->id,
        'private_key_id' => $privateKey->id,
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'is_public' => false,
    ]);
    $config = GithubRunnerConfig::create(['server_id' => $server->id, 'github_app_id' => $githubApp->id, 'labels' => ['coolify'], 'is_enabled' => true]);
    $running = GithubRunnerExecution::create(['github_app_id' => $githubApp->id, 'github_runner_config_id' => $config->id, 'server_id' => $server->id, 'trigger_workflow_job_id' => 1, 'status' => GithubRunnerStatus::Running]);
    $queued = GithubRunnerExecution::create(['github_app_id' => $githubApp->id, 'trigger_workflow_job_id' => 2, 'status' => GithubRunnerStatus::Queued]);

    app(DeleteUserAccount::class)->handle($user, removeTeamResources: true);

    Bus::assertNotDispatched(CleanupGithubRunnerJob::class);
    Bus::assertNotDispatchedSync(CleanupGithubRunnerJob::class);
    expect(User::find($user->id))->toBeNull()
        ->and(GithubApp::find($githubApp->id))->toBeNull()
        ->and(Server::withTrashed()->find($server->id))->toBeNull()
        ->and(GithubRunnerExecution::find($running->id))->toBeNull()
        ->and(GithubRunnerExecution::find($queued->id))->toBeNull()
        ->and(GithubRunnerConfig::find($config->id))->toBeNull();
});
