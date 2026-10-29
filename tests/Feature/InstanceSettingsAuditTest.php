<?php

use App\Actions\Stripe\RefundSubscription;
use App\Livewire\Settings\Advanced;
use App\Livewire\Settings\Index as SettingsIndex;
use App\Livewire\Settings\Updates;
use App\Livewire\SettingsEmail;
use App\Livewire\Subscription\Actions as SubscriptionActions;
use App\Livewire\Upgrade;
use App\Models\AuditEvent;
use App\Models\InstanceSettings;
use App\Models\Subscription;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Once;
use Livewire\Livewire;
use Stripe\Service\SubscriptionService;
use Stripe\StripeClient;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutDefer();
    config()->set('constants.coolify.self_hosted', false);

    InstanceSettings::forceCreate(['id' => 0]);
    Once::flush();

    $this->rootTeam = Team::factory()->create(['id' => 0]);
    $this->user = User::factory()->create();
    $this->rootTeam->members()->attach($this->user->id, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->rootTeam]);
});

function instanceSettingsAuditEvents(string $event)
{
    return AuditEvent::query()->where('event', $event)->get();
}

function mockInstanceSettingsAuditStripe(): SubscriptionService
{
    $stripe = Mockery::mock(StripeClient::class);
    $subscriptions = Mockery::mock(SubscriptionService::class);
    $stripe->subscriptions = $subscriptions;
    app()->instance(StripeClient::class, $stripe);

    return $subscriptions;
}

function createInstanceSettingsAuditSubscription(Team $team, array $attributes = []): Subscription
{
    return Subscription::create([
        'team_id' => $team->id,
        'stripe_subscription_id' => 'sub_audit_123',
        'stripe_customer_id' => 'cus_audit_123',
        'stripe_invoice_paid' => true,
        'stripe_plan_id' => 'price_audit_123',
        'stripe_cancel_at_period_end' => false,
        'stripe_past_due' => false,
        ...$attributes,
    ]);
}

test('advanced settings save records changed field names without the domain connect private key', function () {
    Livewire::test(Advanced::class)
        ->set('is_api_enabled', true)
        ->set('domain_connect_private_key', 'super-secret-private-key-value')
        ->call('submit');

    $events = instanceSettingsAuditEvents('ui.instance.settings.updated');

    expect($events)->toHaveCount(1)
        ->and($events->first()->team_id)->toBeNull()
        ->and($events->first()->resource_type)->toBe('instance')
        ->and($events->first()->metadata['section'])->toBe('advanced')
        ->and($events->first()->metadata['changed_fields'])->toContain('is_api_enabled', 'domain_connect_private_key')
        ->and(json_encode($events->first()->getAttributes()))->not->toContain('super-secret-private-key-value');
});

test('update settings toggle without changes records no event', function () {
    Livewire::test(Updates::class)->call('instantSave');
    $countAfterFirstSave = AuditEvent::query()->count();

    Livewire::test(Updates::class)->call('instantSave');

    expect(AuditEvent::query()->count())->toBe($countAfterFirstSave);
});

test('clearing the domain connect private key is audited only when a key existed', function () {
    instanceSettings()->update(['domain_connect_private_key' => 'existing-private-key-value']);
    Once::flush();

    Livewire::test(Advanced::class)->call('clearDomainConnectPrivateKey');
    Livewire::test(Advanced::class)->call('clearDomainConnectPrivateKey');

    $events = instanceSettingsAuditEvents('ui.instance.settings.updated');

    expect($events)->toHaveCount(1)
        ->and($events->first()->metadata['changed_fields'])->toBe(['domain_connect_private_key'])
        ->and(json_encode($events->first()->getAttributes()))->not->toContain('existing-private-key-value');
});

test('general instance settings save is audited', function () {
    Livewire::test(SettingsIndex::class)
        ->set('instance_name', 'Audited Instance')
        ->call('submit');

    $event = instanceSettingsAuditEvents('ui.instance.settings.updated')->sole();

    expect($event->team_id)->toBeNull()
        ->and($event->metadata['section'])->toBe('general')
        ->and($event->metadata['changed_fields'])->toBe(['instance_name']);
});

test('update settings save is audited', function () {
    Livewire::test(Updates::class)
        ->set('update_check_frequency', '0 */2 * * *')
        ->call('instantSave');

    $event = instanceSettingsAuditEvents('ui.instance.settings.updated')->sole();

    expect($event->team_id)->toBeNull()
        ->and($event->metadata['section'])->toBe('updates')
        ->and($event->metadata['changed_fields'])->toBe(['update_check_frequency']);
});

test('smtp settings save is audited without the smtp password', function () {
    Livewire::test(SettingsEmail::class)
        ->set('smtpEnabled', true)
        ->set('smtpHost', 'smtp.example.com')
        ->set('smtpPort', '587')
        ->set('smtpEncryption', 'starttls')
        ->set('smtpFromAddress', 'from@example.com')
        ->set('smtpFromName', 'Coolify')
        ->set('smtpPassword', 'smtp-secret-password')
        ->call('submitSmtp');

    $event = instanceSettingsAuditEvents('ui.settings.email.updated')->sole();

    expect($event->team_id)->toBeNull()
        ->and($event->metadata['changed_fields'])->toContain('smtp_enabled', 'smtp_host', 'smtp_password')
        ->and(json_encode($event->getAttributes()))->not->toContain('smtp-secret-password');
});

test('resend settings save is audited without the api key', function () {
    Livewire::test(SettingsEmail::class)
        ->set('resendEnabled', true)
        ->set('resendApiKey', 're_secret_api_key')
        ->set('smtpFromAddress', 'from@example.com')
        ->set('smtpFromName', 'Coolify')
        ->call('submitResend');

    $event = instanceSettingsAuditEvents('ui.settings.email.updated')->sole();

    expect($event->metadata['changed_fields'])->toContain('resend_enabled', 'resend_api_key')
        ->and(json_encode($event->getAttributes()))->not->toContain('re_secret_api_key');
});

test('manual instance upgrade is audited', function () {
    Bus::fake();
    config()->set('constants.coolify.version', '4.0.0');
    Cache::put('coolify:versions:all', ['coolify' => ['v4' => ['version' => '4.0.1']]], 3600);

    Livewire::test(Upgrade::class)->call('upgrade');

    $event = instanceSettingsAuditEvents('ui.instance.upgrade_started')->sole();

    expect($event->team_id)->toBeNull()
        ->and($event->resource_type)->toBe('instance')
        ->and($event->metadata['from_version'])->toBe('4.0.0')
        ->and($event->metadata['to_version'])->toBe('4.0.1');
});

test('non-admin upgrade attempt is not audited as started', function () {
    Bus::fake();
    $team = Team::factory()->create();
    $member = User::factory()->create();
    $team->members()->attach($member->id, ['role' => 'owner']);
    $this->actingAs($member);
    session(['currentTeam' => $team]);

    Livewire::test(Upgrade::class)->call('upgrade');

    expect(instanceSettingsAuditEvents('ui.instance.upgrade_started'))->toBeEmpty();
});

describe('subscription changes', function () {
    beforeEach(function () {
        config()->set('subscription.provider', 'stripe');
        config()->set('subscription.stripe_api_key', 'sk_test_fake');

        $this->team = Team::factory()->create(['name' => 'Billing Team']);
        $this->team->members()->attach($this->user->id, ['role' => 'owner']);
        session(['currentTeam' => $this->team]);
    });

    test('scheduling cancellation at period end is audited', function () {
        $subscription = createInstanceSettingsAuditSubscription($this->team);
        mockInstanceSettingsAuditStripe()->shouldReceive('update')
            ->with('sub_audit_123', ['cancel_at_period_end' => true])
            ->once();

        Livewire::test(SubscriptionActions::class)->call('cancelAtPeriodEnd', 'password');

        $event = instanceSettingsAuditEvents('ui.subscription.cancellation_scheduled')->sole();

        expect($event->team_id)->toBe($this->team->id)
            ->and($event->resource_type)->toBe('subscription')
            ->and($event->resource_name)->toBe('Billing Team')
            ->and($event->metadata['subscription_id'])->toBe($subscription->id);
    });

    test('resuming a subscription is audited', function () {
        createInstanceSettingsAuditSubscription($this->team, ['stripe_cancel_at_period_end' => true]);
        mockInstanceSettingsAuditStripe()->shouldReceive('update')
            ->with('sub_audit_123', ['cancel_at_period_end' => false])
            ->once();

        Livewire::test(SubscriptionActions::class)->call('resumeSubscription');

        expect(instanceSettingsAuditEvents('ui.subscription.resumed')->sole()->team_id)->toBe($this->team->id);
    });

    test('immediate cancellation is audited', function () {
        createInstanceSettingsAuditSubscription($this->team);
        mockInstanceSettingsAuditStripe()->shouldReceive('cancel')->with('sub_audit_123')->once();

        Livewire::test(SubscriptionActions::class)->call('cancelImmediately', 'password');

        expect(instanceSettingsAuditEvents('ui.subscription.cancelled')->sole()->team_id)->toBe($this->team->id);
    });

    test('refund is audited and an invalid password records nothing', function () {
        createInstanceSettingsAuditSubscription($this->team);
        $refund = Mockery::mock(RefundSubscription::class);
        $refund->shouldReceive('execute')->once()->andReturn(['success' => true, 'error' => null]);
        app()->instance(RefundSubscription::class, $refund);

        Livewire::test(SubscriptionActions::class)->call('refundSubscription', 'wrong-password');
        expect(instanceSettingsAuditEvents('ui.subscription.refunded'))->toBeEmpty();

        Livewire::test(SubscriptionActions::class)->call('refundSubscription', 'password');
        expect(instanceSettingsAuditEvents('ui.subscription.refunded')->sole()->team_id)->toBe($this->team->id);
    });

    test('immediate refund is audited only when it succeeds', function (bool $success) {
        $subscription = createInstanceSettingsAuditSubscription($this->team);
        $refund = Mockery::mock(RefundSubscription::class);
        $refund->shouldReceive('execute')->once()->andReturn(['success' => $success, 'error' => null]);
        app()->instance(RefundSubscription::class, $refund);

        Livewire::test(SubscriptionActions::class)
            ->call('cancelImmediately', 'password', ['refundLatestPayment'])
            ->assertDispatched($success ? 'success' : 'error');

        $events = instanceSettingsAuditEvents('ui.subscription.refunded');

        if ($success) {
            expect($events->sole()->team_id)->toBe($this->team->id)
                ->and($events->sole()->metadata['subscription_id'])->toBe($subscription->id);
        } else {
            expect($events)->toBeEmpty();
        }
    })->with([true, false]);

    test('server limit change is audited', function () {
        Bus::fake();
        createInstanceSettingsAuditSubscription($this->team);
        $this->team->update(['custom_server_limit' => 2]);
        $subscriptions = mockInstanceSettingsAuditStripe();
        $subscriptions->shouldReceive('retrieve')->with('sub_audit_123')->andReturn((object) [
            'items' => (object) ['data' => [(object) ['id' => 'si_audit', 'quantity' => 2]]],
        ]);
        $subscriptions->shouldReceive('update')->once()->andReturn((object) ['latest_invoice' => null]);

        Livewire::test(SubscriptionActions::class)
            ->set('quantity', 5)
            ->call('updateQuantity');

        $event = instanceSettingsAuditEvents('ui.subscription.quantity_updated')->sole();

        expect($event->team_id)->toBe($this->team->id)
            ->and($event->metadata['from_server_limit'])->toBe(2)
            ->and($event->metadata['to_server_limit'])->toBe(5);
    });
});
