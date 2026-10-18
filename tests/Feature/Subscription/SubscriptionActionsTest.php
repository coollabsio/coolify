<?php

use App\Actions\Stripe\RefundSubscription;
use App\Actions\User\DeleteUserAccount;
use App\Livewire\Subscription\Actions;
use App\Models\Application;
use App\Models\Environment;
use App\Models\GithubApp;
use App\Models\InstanceSettings;
use App\Models\OauthIdentity;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Subscription;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Stripe\Service\InvoiceService;
use Stripe\Service\RefundService;
use Stripe\Service\SubscriptionService;
use Stripe\StripeClient;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('constants.coolify.self_hosted', false);
    config()->set('subscription.provider', 'stripe');
    config()->set('subscription.stripe_api_key', 'sk_test_fake');

    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0]));

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    Subscription::create([
        'team_id' => $this->team->id,
        'stripe_subscription_id' => 'sub_test_123',
        'stripe_customer_id' => 'cus_test_123',
        'stripe_invoice_paid' => true,
        'stripe_plan_id' => 'price_test_123',
        'stripe_cancel_at_period_end' => false,
        'stripe_past_due' => false,
    ]);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

describe('cancelImmediately with refund option', function () {
    test('refunds and cancels via RefundSubscription when refund checkbox is selected', function () {
        $mock = Mockery::mock(RefundSubscription::class);
        $mock->shouldReceive('execute')->once()->andReturn(['success' => true, 'error' => null]);
        $this->instance(RefundSubscription::class, $mock);

        Livewire::test(Actions::class)
            ->call('cancelImmediately', 'password', ['refundLatestPayment'])
            ->assertDispatched('success')
            ->assertRedirect(route('subscription.index'));
    });

    test('dispatches error when refund fails', function () {
        $mock = Mockery::mock(RefundSubscription::class);
        $mock->shouldReceive('execute')->once()->andReturn(['success' => false, 'error' => 'No paid invoice found to refund.']);
        $this->instance(RefundSubscription::class, $mock);

        Livewire::test(Actions::class)
            ->call('cancelImmediately', 'password', ['refundLatestPayment'])
            ->assertDispatched('error');
    });

    test('rejects invalid password before refunding', function () {
        $mock = Mockery::mock(RefundSubscription::class);
        $mock->shouldNotReceive('execute');
        $this->instance(RefundSubscription::class, $mock);

        Livewire::test(Actions::class)
            ->call('cancelImmediately', 'wrong-password', ['refundLatestPayment'])
            ->assertReturned('Invalid password.');
    });
});

describe('password confirmation', function () {
    test('a user with a linked oauth identity cancels without a password', function () {
        OauthIdentity::create([
            'user_id' => $this->user->id,
            'provider' => 'oidc',
            'issuer' => 'https://idp.example.com',
            'provider_user_id' => 'oauth-user-id',
        ]);
        $mock = Mockery::mock(RefundSubscription::class);
        $mock->shouldReceive('execute')->once()->andReturn(['success' => true, 'error' => null]);
        $this->instance(RefundSubscription::class, $mock);

        Livewire::test(Actions::class)
            ->call('cancelImmediately', '', ['refundLatestPayment'])
            ->assertDispatched('success')
            ->assertRedirect(route('subscription.index'));
    });

    test('a user without a linked oauth identity is rejected with an empty password', function () {
        $mock = Mockery::mock(RefundSubscription::class);
        $mock->shouldNotReceive('execute');
        $this->instance(RefundSubscription::class, $mock);

        Livewire::test(Actions::class)
            ->call('refundSubscription', '')
            ->assertReturned('Invalid password.');
    });
});

describe('cancelImmediately with account deletion', function () {
    beforeEach(function () {
        $this->stripe = Mockery::mock(StripeClient::class);
        $this->stripe->subscriptions = Mockery::mock(SubscriptionService::class);
        $this->instance(StripeClient::class, $this->stripe);
    });

    test('cancels the subscription, removes the team data from coolify, and deletes the account', function () {
        $this->stripe->subscriptions->shouldReceive('cancel')->once()->with('sub_test_123');
        $privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);
        $server = Server::factory()->create(['team_id' => $this->team->id, 'private_key_id' => $privateKey->id]);
        $destination = StandaloneDocker::where('server_id', $server->id)->firstOrFail();
        $project = Project::factory()->create(['team_id' => $this->team->id]);
        $environment = Environment::factory()->create(['project_id' => $project->id]);
        $application = Application::factory()->create([
            'environment_id' => $environment->id,
            'destination_id' => $destination->id,
            'destination_type' => StandaloneDocker::class,
        ]);
        $githubApp = GithubApp::create([
            'name' => 'Team GitHub App',
            'team_id' => $this->team->id,
            'private_key_id' => $privateKey->id,
            'api_url' => 'https://api.github.com',
            'html_url' => 'https://github.com',
            'is_public' => false,
        ]);

        Livewire::test(Actions::class)
            ->call('cancelImmediately', 'password', ['deleteAccount'])
            ->assertRedirect(route('login'));

        expect(User::find($this->user->id))->toBeNull()
            ->and(Team::find($this->team->id))->toBeNull()
            ->and(Server::withTrashed()->find($server->id))->toBeNull()
            ->and(Project::find($project->id))->toBeNull()
            ->and(Application::withTrashed()->find($application->id))->toBeNull()
            ->and(GithubApp::find($githubApp->id))->toBeNull()
            ->and(Subscription::where('team_id', $this->team->id)->exists())->toBeFalse()
            ->and(auth()->check())->toBeFalse();
    });

    test('refunds, cancels, and deletes the account when both options are selected', function () {
        $refund = Mockery::mock(RefundSubscription::class);
        $refund->shouldReceive('execute')->once()->andReturnUsing(function (Team $team) {
            $team->subscriptionEnded();

            return ['success' => true, 'error' => null];
        });
        $this->instance(RefundSubscription::class, $refund);
        $this->stripe->subscriptions->shouldNotReceive('cancel');

        Livewire::test(Actions::class)
            ->call('cancelImmediately', 'password', ['refundLatestPayment', 'deleteAccount'])
            ->assertRedirect(route('login'));

        expect(User::find($this->user->id))->toBeNull();
    });

    test('keeps the account when cancellation fails after a successful refund', function () {
        $this->stripe->invoices = Mockery::mock(InvoiceService::class);
        $this->stripe->refunds = Mockery::mock(RefundService::class);
        $this->stripe->subscriptions->shouldReceive('retrieve')->with('sub_test_123')->andReturn((object) [
            'status' => 'active',
            'start_date' => now()->subDays(10)->timestamp,
            'current_period_end' => now()->addDays(20)->timestamp,
        ]);
        $this->stripe->invoices->shouldReceive('all')->andReturn((object) ['data' => [
            (object) ['payment_intent' => 'pi_test_123'],
        ]]);
        $this->stripe->refunds->shouldReceive('create')->once()
            ->with(['payment_intent' => 'pi_test_123'])
            ->andReturn((object) ['id' => 're_test_123']);
        $this->stripe->subscriptions->shouldReceive('cancel')->once()->with('sub_test_123')
            ->andThrow(new RuntimeException('Stripe cancel API error'));

        Livewire::test(Actions::class)
            ->call('cancelImmediately', 'password', ['refundLatestPayment', 'deleteAccount'])
            ->assertDispatched('error')
            ->assertNotDispatched('success')
            ->assertNoRedirect();

        $this->assertModelExists($this->user);
        $this->assertModelExists($this->team);
        expect($this->team->subscription->fresh()->stripe_subscription_id)->toBe('sub_test_123')
            ->and($this->team->subscription->fresh()->stripe_refunded_at)->not->toBeNull()
            ->and(auth()->check())->toBeTrue();
    });

    test('does not cancel when the account cannot be deleted', function () {
        $member = User::factory()->create();
        $sharedTeam = Team::factory()->create(['name' => 'Shared']);
        $sharedTeam->members()->attach($this->user->id, ['role' => 'owner']);
        $sharedTeam->members()->attach($member->id, ['role' => 'member']);
        $this->stripe->subscriptions->shouldNotReceive('cancel');

        Livewire::test(Actions::class)
            ->call('cancelImmediately', 'password', ['deleteAccount'])
            ->assertReturned('Make another member of the team "Shared" an admin or owner.');

        expect(User::find($this->user->id))->not->toBeNull()
            ->and($this->team->subscription->fresh()->stripe_subscription_id)->toBe('sub_test_123');
    });

    test('keeps the account when delete my account is not selected', function () {
        $this->stripe->subscriptions->shouldReceive('cancel')->once()->with('sub_test_123');

        Livewire::test(Actions::class)
            ->call('cancelImmediately', 'password', [])
            ->assertDispatched('success')
            ->assertRedirect(route('subscription.index'));

        expect(User::find($this->user->id))->not->toBeNull()
            ->and(Team::find($this->team->id))->not->toBeNull();
    });

    test('does nothing with a wrong password', function () {
        $this->stripe->subscriptions->shouldNotReceive('cancel');

        Livewire::test(Actions::class)
            ->call('cancelImmediately', 'wrong-password', ['deleteAccount'])
            ->assertReturned('Invalid password.');

        expect(User::find($this->user->id))->not->toBeNull();
    });

    test('keeps the account and reports an error when deletion fails after the cancellation', function () {
        $this->stripe->subscriptions->shouldReceive('cancel')->once()->with('sub_test_123');
        $deleteAccount = Mockery::mock(DeleteUserAccount::class)->makePartial();
        $deleteAccount->shouldReceive('handle')->once()->andThrow(new RuntimeException('Runner busy'));
        $this->instance(DeleteUserAccount::class, $deleteAccount);

        Livewire::test(Actions::class)
            ->call('cancelImmediately', 'password', ['deleteAccount'])
            ->assertDispatched('error')
            ->assertRedirect(route('subscription.index'));

        expect(User::find($this->user->id))->not->toBeNull()
            ->and(auth()->check())->toBeTrue();
    });
});
