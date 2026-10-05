<?php

use App\Jobs\NotifyNodeHealthJob;
use App\Jobs\SendMessageToTelegramJob;
use App\Models\InstanceSettings;
use App\Models\Node;
use App\Models\NodeCluster;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use App\Notifications\Channels\EmailChannel;
use App\Notifications\Channels\TelegramChannel;
use App\Notifications\Node\ClusterNetworkRecovered;
use App\Notifications\Node\ClusterNetworkUnhealthy;
use App\Notifications\Node\Reachable;
use App\Notifications\Node\Unreachable;
use App\Notifications\Server\Reachable as ServerReachable;
use App\Notifications\Server\Unreachable as ServerUnreachable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function enableReachabilityEmails(Team $team, bool $enabled = true): void
{
    $team->emailNotificationSettings()->update([
        'use_instance_email_settings' => true,
        'server_unreachable_email_notifications' => $enabled,
        'server_reachable_email_notifications' => $enabled,
    ]);
}

function connectNode(Node $node): void
{
    Cache::put($node->cacheKey(), [
        'status' => 'connected',
        'connection_id' => (string) str()->uuid(),
        'last_heartbeat_at' => now()->toIso8601String(),
    ], now()->addMinutes(5));
}

function runNodeHealthCheck(): void
{
    (new NotifyNodeHealthJob)->handle();
}

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    $this->team = Team::factory()->create();
    enableReachabilityEmails($this->team);
    $this->otherTeam = Team::factory()->create();
    enableReachabilityEmails($this->otherTeam);

    $key = PrivateKey::factory()->create(['team_id' => $this->team->id]);
    $this->cluster = NodeCluster::factory()->create(['team_id' => $this->team->id, 'network_status' => 'active']);
    $this->node = Node::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $key->id,
        'node_cluster_id' => $this->cluster->id,
        'is_usable' => true,
        'is_reachable' => true,
    ]);

    Notification::fake();
});

it('notifies once when a Node stays unreachable longer than the grace period', function () {
    runNodeHealthCheck();
    expect($this->node->refresh()->unreachable_since)->not->toBeNull();

    $this->travel(2)->minutes();
    runNodeHealthCheck();
    Notification::assertNothingSent();

    $this->travel(2)->minutes();
    runNodeHealthCheck();
    runNodeHealthCheck();
    $this->travel(5)->minutes();
    runNodeHealthCheck();

    Notification::assertSentToTimes($this->team, Unreachable::class, 1);
    Notification::assertSentTo($this->team, Unreachable::class, fn (Unreachable $notification, array $channels) => $notification->node->is($this->node)
        && $channels === [EmailChannel::class]
        && str_contains($notification->toWebhook()['url'], "/cluster-server/{$this->node->uuid}"));
    Notification::assertNotSentTo($this->otherTeam, Unreachable::class);
    expect($this->node->refresh()->unreachable_notified_at)->not->toBeNull();
});

it('does not notify when Sentinel reconnects within the grace period', function () {
    connectNode($this->node);
    runNodeHealthCheck();

    Cache::put($this->node->cacheKey(), ['status' => 'reconnecting'], now()->addSeconds(15));
    runNodeHealthCheck();
    $this->travel(1)->minutes();
    Cache::forget($this->node->cacheKey());
    runNodeHealthCheck();

    $this->travel(1)->minutes();
    connectNode($this->node);
    runNodeHealthCheck();

    $this->travel(10)->minutes();
    connectNode($this->node);
    runNodeHealthCheck();

    Notification::assertNothingSent();
    expect($this->node->refresh()->unreachable_since)->toBeNull()
        ->and($this->node->unreachable_notified_at)->toBeNull();
});

it('sends one recovery notification after an unreachable notification', function () {
    $this->node->forceFill(['unreachable_since' => now()->subMinutes(10), 'unreachable_notified_at' => now()->subMinutes(7)])->save();
    connectNode($this->node);

    runNodeHealthCheck();
    runNodeHealthCheck();

    Notification::assertSentToTimes($this->team, Reachable::class, 1);
    Notification::assertNotSentTo($this->team, Unreachable::class);
    Notification::assertNotSentTo($this->otherTeam, Reachable::class);
    expect($this->node->refresh()->unreachable_since)->toBeNull()
        ->and($this->node->unreachable_notified_at)->toBeNull();
});

it('sends no recovery notification without a prior unreachable notification', function () {
    $this->node->forceFill(['unreachable_since' => now()->subMinutes(2)])->save();
    connectNode($this->node);

    runNodeHealthCheck();

    Notification::assertNothingSent();
    expect($this->node->refresh()->unreachable_since)->toBeNull();
});

it('ignores Nodes that are not usable', function () {
    $this->node->update(['is_usable' => false]);

    runNodeHealthCheck();
    $this->travel(10)->minutes();
    runNodeHealthCheck();

    Notification::assertNothingSent();
});

it('respects the reachability notification toggles', function () {
    enableReachabilityEmails($this->team, false);
    $this->node->forceFill(['unreachable_since' => now()->subMinutes(10)])->save();
    $this->cluster->update(['network_status' => 'error']);

    runNodeHealthCheck();

    Notification::assertNothingSent();
});

it('notifies once when the cluster network fails and once when it recovers', function () {
    connectNode($this->node);

    $this->cluster->update(['network_status' => 'reconciling']);
    runNodeHealthCheck();
    Notification::assertNothingSent();

    $this->cluster->update(['network_status' => 'error']);
    runNodeHealthCheck();
    runNodeHealthCheck();

    $this->cluster->update(['network_status' => 'reconciling']);
    runNodeHealthCheck();
    $this->cluster->update(['network_status' => 'error']);
    runNodeHealthCheck();

    Notification::assertSentToTimes($this->team, ClusterNetworkUnhealthy::class, 1);
    Notification::assertSentTo($this->team, ClusterNetworkUnhealthy::class, fn (ClusterNetworkUnhealthy $notification) => $notification->cluster->is($this->cluster)
        && $notification->networkStatus === 'error'
        && $notification->affectedNodeNames === [$this->node->name]
        && str_contains($notification->toWebhook()['url'], "/cluster/{$this->cluster->uuid}"));
    Notification::assertNotSentTo($this->team, ClusterNetworkRecovered::class);

    $this->cluster->update(['network_status' => 'active']);
    runNodeHealthCheck();
    runNodeHealthCheck();

    Notification::assertSentToTimes($this->team, ClusterNetworkRecovered::class, 1);
    Notification::assertNotSentTo($this->otherTeam, ClusterNetworkUnhealthy::class);
    Notification::assertNotSentTo($this->otherTeam, ClusterNetworkRecovered::class);
    expect($this->cluster->refresh()->network_unhealthy_notified_at)->toBeNull();
});

it('notifies when the cluster network is degraded', function () {
    connectNode($this->node);
    $this->cluster->update(['network_status' => 'degraded']);

    runNodeHealthCheck();

    Notification::assertSentToTimes($this->team, ClusterNetworkUnhealthy::class, 1);
    Notification::assertSentTo($this->team, ClusterNetworkUnhealthy::class, fn (ClusterNetworkUnhealthy $notification) => $notification->networkStatus === 'degraded');
});

it('does not notify for a healthy or reconciling cluster that was never unhealthy', function () {
    connectNode($this->node);

    runNodeHealthCheck();
    $this->cluster->update(['network_status' => 'reconciling']);
    runNodeHealthCheck();
    $this->cluster->update(['network_status' => 'active']);
    runNodeHealthCheck();

    Notification::assertNothingSent();
});

it('keeps the cluster outage open while its Nodes wait for a network revision', function () {
    connectNode($this->node);
    $this->cluster->forceFill(['network_status' => 'pending', 'network_unhealthy_notified_at' => now()])->save();

    runNodeHealthCheck();
    $this->cluster->update(['network_status' => 'error']);
    runNodeHealthCheck();

    Notification::assertNothingSent();
    expect($this->cluster->refresh()->network_unhealthy_notified_at)->not->toBeNull();
});

it('ends a cluster outage silently when its last Node leaves', function () {
    connectNode($this->node);
    $this->node->update(['node_cluster_id' => null]);
    $this->cluster->forceFill(['network_status' => 'pending', 'network_unhealthy_notified_at' => now()])->save();

    runNodeHealthCheck();

    Notification::assertNothingSent();
    expect($this->cluster->refresh()->network_unhealthy_notified_at)->toBeNull();
});

it('routes Node notifications to the reachability Telegram threads', function () {
    Queue::fake();
    $this->team->telegramNotificationSettings->update([
        'telegram_enabled' => true,
        'telegram_token' => 'test-token',
        'telegram_chat_id' => '-1001234567890',
        'telegram_notifications_server_unreachable_thread_id' => '11',
        'telegram_notifications_server_reachable_thread_id' => '22',
    ]);
    $team = $this->team->fresh();

    $expectations = [
        [new Unreachable($this->node), '11'],
        [new ClusterNetworkUnhealthy($this->cluster, 'error'), '11'],
        [new Reachable($this->node), '22'],
        [new ClusterNetworkRecovered($this->cluster), '22'],
    ];
    foreach ($expectations as [$notification, $threadId]) {
        (new TelegramChannel)->send($team, $notification);
    }

    Queue::assertPushed(SendMessageToTelegramJob::class, 4);
    Queue::assertPushed(SendMessageToTelegramJob::class, fn (SendMessageToTelegramJob $job) => $job->threadId === '11' && str_contains($job->text, 'has not reported'));
    Queue::assertPushed(SendMessageToTelegramJob::class, fn (SendMessageToTelegramJob $job) => $job->threadId === '11' && str_contains($job->text, 'is failed'));
    Queue::assertPushed(SendMessageToTelegramJob::class, fn (SendMessageToTelegramJob $job) => $job->threadId === '22' && str_contains($job->text, 'connected to Coolify again'));
    Queue::assertPushed(SendMessageToTelegramJob::class, fn (SendMessageToTelegramJob $job) => $job->threadId === '22' && str_contains($job->text, 'active again'));
});

it('renders every channel payload', function () {
    $notifications = [
        new Unreachable($this->node),
        new Reachable($this->node),
        new ClusterNetworkUnhealthy($this->cluster, 'error', [$this->node->name]),
        new ClusterNetworkRecovered($this->cluster),
    ];

    foreach ($notifications as $notification) {
        expect((string) $notification->toMail()->render())->toContain($notification->toWebhook()['url'])
            ->and($notification->toDiscord())->not->toBeNull()
            ->and($notification->toTelegram()['message'])->toStartWith('Coolify: ')
            ->and($notification->toPushover()->title)->not->toBeEmpty()
            ->and($notification->toSlack()->description)->toContain($notification->toWebhook()['url']);
    }
});

it('schedules the Node health check every minute on one server', function () {
    $event = collect(app(Schedule::class)->events())->first(
        fn ($event) => str_contains((string) $event->description, NotifyNodeHealthJob::class),
    );

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('* * * * *')
        ->and($event->onOneServer)->toBeTrue();
});

it('sends cluster server webhooks with the same events as Docker servers', function () {
    $server = Server::factory()->create(['team_id' => $this->node->team_id, 'private_key_id' => $this->node->private_key_id]);

    expect((new Unreachable($this->node))->toWebhook())->toMatchArray([
        'event' => 'server_unreachable',
        'server_name' => $this->node->name,
        'server_uuid' => $this->node->uuid,
        'server_type' => 'cluster',
    ])->not->toHaveKeys(['node_name', 'node_uuid'])
        ->and((new Reachable($this->node))->toWebhook())->toMatchArray([
            'event' => 'server_reachable',
            'server_uuid' => $this->node->uuid,
            'server_type' => 'cluster',
        ])
        ->and((new ServerUnreachable($server))->toWebhook())->toMatchArray([
            'event' => 'server_unreachable',
            'server_uuid' => $server->uuid,
            'server_type' => 'docker',
        ])
        ->and((new ServerReachable($server))->toWebhook())->toMatchArray([
            'event' => 'server_reachable',
            'server_type' => 'docker',
        ])
        ->and((new ClusterNetworkUnhealthy($this->cluster, 'degraded', [$this->node->name]))->toWebhook())->toMatchArray([
            'event' => 'cluster_network_unhealthy',
            'cluster_uuid' => $this->cluster->uuid,
            'network_status' => 'degraded',
            'affected_servers' => [$this->node->name],
        ])
        ->and((new ClusterNetworkRecovered($this->cluster))->toWebhook())->toMatchArray([
            'event' => 'cluster_network_recovered',
            'cluster_uuid' => $this->cluster->uuid,
        ]);
});
