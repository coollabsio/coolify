<?php

use App\Enums\ApplicationDeploymentStatus;
use App\Jobs\ImageRescanJob;
use App\Jobs\ImageScanDigestJob;
use App\Jobs\SendMessageToTelegramJob;
use App\Jobs\ServerManagerJob;
use App\Livewire\Notifications\Discord;
use App\Livewire\Notifications\Email;
use App\Livewire\Notifications\Pushover;
use App\Livewire\Notifications\Slack;
use App\Livewire\Notifications\Telegram;
use App\Livewire\Notifications\Webhook;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\ImageScan;
use App\Models\ImageScanIgnore;
use App\Models\ImageScanNotificationState;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use App\Notifications\Application\DeploymentFailed;
use App\Notifications\Channels\EmailChannel;
use App\Notifications\Channels\TelegramChannel;
use App\Notifications\ImageScan\KnownExploitedFound;
use App\Notifications\ImageScan\VulnerabilityDigest;
use App\Services\ImageScan\ScanNotifications;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    Server::flushIdentityMap();
    config(['app.url' => 'http://localhost']);
    InstanceSettings::forceCreate(['id' => 0, 'is_image_scanning_allowed' => true, 'fqdn' => 'https://coolify.example.com']);
    Notification::fake();

    $this->team = Team::factory()->create();
    [$this->server, $this->destination, $this->environment] = notifyTeamSetup($this->team);
    $this->application = notifyApplication('api');
    notifyEnableDiscord($this->team);
});

/**
 * @return array{0: Server, 1: StandaloneDocker, 2: Environment}
 */
function notifyTeamSetup(Team $team): array
{
    $server = Server::factory()->create(['team_id' => $team->id, 'name' => 'scan-server-'.$team->id]);
    $server->settings->forceFill(['is_image_scan_enabled' => true, 'is_reachable' => true, 'is_usable' => true])->saveQuietly();
    $destination = StandaloneDocker::query()->where('server_id', $server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    return [$server->fresh(), $destination, $environment];
}

function notifyApplication(string $name, ?Environment $environment = null, ?StandaloneDocker $destination = null): Application
{
    $destination ??= test()->destination;

    return Application::factory()->create([
        'name' => $name,
        'environment_id' => ($environment ?? test()->environment)->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'build_pack' => 'dockerimage',
        'docker_registry_image_name' => 'nginx',
        'docker_registry_image_tag' => '1.27',
    ]);
}

function notifyEnableDiscord(Team $team): void
{
    $team->discordNotificationSettings->update([
        'discord_enabled' => true,
        'discord_webhook_url' => 'https://discord.com/api/webhooks/1/abc',
        'image_vulnerabilities_discord_notifications' => true,
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function notifyFinding(string $id, array $overrides = []): array
{
    return [
        'id' => $id, 'aliases' => [], 'severity' => 'critical', 'package' => 'openssl', 'version' => '3.0.1',
        'type' => 'apk', 'artifactId' => 'a-'.$id, 'fixedIn' => '3.0.2', 'fixState' => 'fixed', 'kev' => false,
        'epss' => 0.2, 'risk' => 1.0, 'summary' => 'A bug.', 'layer' => 'app',
        ...$overrides,
    ];
}

/**
 * A completed scan. Unless `$state` is false, the resource also gets a state row that is not a baseline and has
 * nothing notified yet (when it has none).
 *
 * @param  list<array<string, mixed>>  $findings
 * @param  array<string, mixed>  $attributes
 */
function notifyScan(Model $resource, array $findings, array $attributes = [], bool $state = true): ImageScan
{
    if ($state && notifyStateOf($resource) === null) {
        notifyState($resource, []);
    }

    return ImageScan::factory()->create([
        'server_id' => test()->server->id,
        'resource_type' => $resource->getMorphClass(),
        'resource_id' => $resource->getKey(),
        'image_ref' => 'nginx:1.27',
        'builds_image' => true,
        'findings' => $findings,
        'summary' => [],
        'is_baseline' => false,
        ...$attributes,
    ]);
}

/**
 * @param  list<string>  $ids
 * @param  list<string>  $candidates
 */
function notifyState(Model $resource, array $ids, array $candidates = [], bool $baseline = false, ?int $deploymentId = null): ImageScanNotificationState
{
    return ImageScanNotificationState::query()->updateOrCreate(
        ['resource_type' => $resource->getMorphClass(), 'resource_id' => $resource->getKey()],
        ['team_id' => test()->team->id, 'notified_ids' => $ids, 'notified_candidates' => $candidates, 'is_baseline' => $baseline, 'baseline_deployment_queue_id' => $deploymentId],
    );
}

function notifyStateOf(Model $resource): ?ImageScanNotificationState
{
    return ImageScanNotificationState::query()->where('resource_type', $resource->getMorphClass())->where('resource_id', $resource->getKey())->first();
}

/**
 * @return array<string, mixed>
 */
function notifyCandidates(string $ref = 'nginx:1.28'): array
{
    return ['status' => 'ok', 'checked_at' => now()->toIso8601String(), 'entries' => [[
        'ref' => $ref, 'digest' => 'sha256:'.str_repeat('b', 64), 'kind' => 'line', 'better' => true,
        'fixed_ids' => ['CVE-2026-0001'], 'fixes' => ['critical' => 1], 'left' => [],
    ]]];
}

function notifyDeployment(Application $application, int $pullRequestId = 0): ApplicationDeploymentQueue
{
    return ApplicationDeploymentQueue::create([
        'application_id' => $application->id,
        'deployment_uuid' => (string) Str::uuid(),
        'server_id' => test()->server->id,
        'server_name' => test()->server->name,
        'destination_id' => $application->destination_id,
        'pull_request_id' => $pullRequestId,
        'status' => ApplicationDeploymentStatus::FINISHED->value,
    ]);
}

/**
 * A long resource line for the channel limits.
 *
 * @return array<string, mixed>
 */
function notifyLongResource(int $index): array
{
    return [
        'name' => str_repeat('n', 95).$index, 'uuid' => "uuid-{$index}", 'url' => "https://coolify.example.com/project/p/environment/e/application/{$index}/vulnerabilities",
        'counts' => ['critical' => 3, 'high' => 2], 'total' => 5, 'worst' => 'critical', 'known_exploited' => 1,
        'fix' => 'Update ghcr.io/'.str_repeat('a', 150).':1.0 to 1.1.', 'ids' => ['CVE-2026-0001'], 'updates' => [],
    ];
}

function notifyRunDigest(?Team $team = null): void
{
    (new ImageScanDigestJob($team ?? test()->team))->handle();
}

function notifySentDigest(?Team $team = null): VulnerabilityDigest
{
    $sent = null;
    Notification::assertSentTo($team ?? test()->team, VulnerabilityDigest::class, function (VulnerabilityDigest $notification) use (&$sent) {
        $sent = $notification;

        return true;
    });

    return $sent;
}

/**
 * Every chat and webhook payload of a notification, JSON encoded.
 *
 * @return array<string, string>
 */
function notifyChatPayloads(object $notification): array
{
    return [
        'discord' => json_encode($notification->toDiscord()->toPayload()),
        'slack' => json_encode(get_object_vars($notification->toSlack())),
        'telegram' => json_encode($notification->toTelegram()),
        'pushover' => json_encode($notification->toPushover()->toPayload('token', 'user')),
        'webhook' => json_encode($notification->toWebhook()),
    ];
}

describe('notification state', function () {
    it('creates a baseline for the first deploy of an image and sends nothing', function () {
        $deployment = notifyDeployment($this->application);
        $scan = notifyScan($this->application, [notifyFinding('CVE-2026-0001', ['kev' => true]), notifyFinding('CVE-2026-0002'), notifyFinding('CVE-2026-0003', ['fixState' => 'not-fixed', 'fixedIn' => null])], [
            'is_baseline' => true, 'deployment_queue_id' => $deployment->id,
        ], state: false);

        ScanNotifications::afterScan($scan, $deployment->id);
        notifyRunDigest();

        Notification::assertNothingSent();
        expect(notifyStateOf($this->application)->notified_ids)->toBe(['CVE-2026-0001', 'CVE-2026-0002'])
            ->and(notifyStateOf($this->application)->is_baseline)->toBeFalse();
    });

    it('treats both images of a first Compose deployment as a baseline', function () {
        $deployment = notifyDeployment($this->application);
        $web = notifyScan($this->application, [notifyFinding('CVE-2026-0001')], ['is_baseline' => true, 'deployment_queue_id' => $deployment->id, 'image_ref' => 'web:1'], state: false);
        ScanNotifications::afterScan($web, $deployment->id);
        $worker = notifyScan($this->application, [notifyFinding('CVE-2026-0002', ['kev' => true])], ['deployment_queue_id' => $deployment->id, 'image_ref' => 'worker:1'], state: false);
        ScanNotifications::afterScan($worker, $deployment->id);
        $worker->update(['candidates' => notifyCandidates('worker:2')]);
        ScanNotifications::afterScan($worker->fresh(), candidateCheck: true);

        notifyRunDigest();
        notifyRunDigest();

        Notification::assertNothingSent();
        expect(notifyStateOf($this->application)->notified_ids)->toEqualCanonicalizing(['CVE-2026-0001', 'CVE-2026-0002']);
    });

    it('reports a new critical of a redeploy that comes before the first digest', function () {
        $first = notifyDeployment($this->application);
        $scan = notifyScan($this->application, [notifyFinding('CVE-2026-0001')], ['is_baseline' => true, 'deployment_queue_id' => $first->id, 'scanned_at' => now()->subHour()], state: false);
        ScanNotifications::afterScan($scan, $first->id);

        $second = notifyDeployment($this->application);
        $redeploy = notifyScan($this->application, [notifyFinding('CVE-2026-0001'), notifyFinding('CVE-2026-0002')], ['deployment_queue_id' => $second->id], state: false);
        ScanNotifications::afterScan($redeploy, $second->id);
        notifyRunDigest();

        Notification::assertSentToTimes($this->team, VulnerabilityDigest::class, 1);
        expect(notifySentDigest()->digest['resources'][0]['ids'])->toBe(['CVE-2026-0002']);
    });

    it('alerts right away for a new Compose service image with a known exploited finding, in any order', function (array $order) {
        $old = notifyDeployment($this->application);
        $rows = [
            'web' => notifyScan($this->application, [notifyFinding('CVE-2026-0001')], ['deployment_queue_id' => $old->id, 'image_ref' => 'web:1', 'scanned_at' => now()->subDay()], state: false),
            'worker' => notifyScan($this->application, [notifyFinding('CVE-2026-0002', ['kev' => true])], ['deployment_queue_id' => $old->id, 'image_ref' => 'worker:1', 'scanned_at' => now()->subDay()], state: false),
        ];
        notifyState($this->application, ['CVE-2026-0001', 'CVE-2026-0002']);
        $new = notifyDeployment($this->application);
        // The image of the new Compose service, before the deploy scan writes its results.
        $rows['cache'] = notifyScan($this->application, [notifyFinding('CVE-2026-0003', ['kev' => true])], ['image_ref' => 'cache:1', 'scanned_at' => now()->subDays(5)], state: false);

        foreach ($order as $name) {
            // The deploy rescans reused rows, which then belong to the new deployment.
            $rows[$name]->update(['deployment_queue_id' => $new->id, 'scanned_at' => now()]);
            ScanNotifications::afterScan($rows[$name]->fresh(), $new->id);
            $this->travel(1)->seconds();
        }
        notifyRunDigest();

        Notification::assertSentToTimes($this->team, KnownExploitedFound::class, 1);
        Notification::assertSentTo($this->team, KnownExploitedFound::class, fn (KnownExploitedFound $notification) => $notification->ids === ['CVE-2026-0003'] && $notification->imageRef === 'cache:1');
        Notification::assertNotSentTo($this->team, VulnerabilityDigest::class);
    })->with([
        'new image first' => [['cache', 'web', 'worker']],
        'new image last' => [['web', 'worker', 'cache']],
    ]);

    it('does not notify again when unchanged images are rescanned in any order', function (array $order) {
        $old = notifyDeployment($this->application);
        $rows = [
            'web' => notifyScan($this->application, [notifyFinding('CVE-2026-0001')], ['deployment_queue_id' => $old->id, 'image_ref' => 'web:1', 'scanned_at' => now()->subDay()], state: false),
            'worker' => notifyScan($this->application, [notifyFinding('CVE-2026-0002', ['kev' => true])], ['deployment_queue_id' => $old->id, 'image_ref' => 'worker:1', 'scanned_at' => now()->subDay()], state: false),
        ];
        notifyState($this->application, ['CVE-2026-0001', 'CVE-2026-0002']);
        $new = notifyDeployment($this->application);

        foreach ($order as $name) {
            $rows[$name]->update(['deployment_queue_id' => $new->id, 'scanned_at' => now()]);
            ScanNotifications::afterScan($rows[$name]->fresh(), $new->id);
            $this->travel(1)->seconds();
        }
        foreach ($order as $name) {
            ScanNotifications::afterScan($rows[$name]->fresh());
        }
        notifyRunDigest();

        Notification::assertNothingSent();
    })->with([
        'web first' => [['web', 'worker']],
        'worker first' => [['worker', 'web']],
    ]);

    it('sends one digest for new fixable findings on the next run, not two', function () {
        notifyScan($this->application, [
            notifyFinding('CVE-2026-0001'), notifyFinding('CVE-2026-0002'), notifyFinding('CVE-2026-0003'),
            notifyFinding('CVE-2026-0004', ['severity' => 'low']),
        ]);
        notifyState($this->application, ['CVE-2026-0001']);

        notifyRunDigest();
        notifyRunDigest();

        Notification::assertSentToTimes($this->team, VulnerabilityDigest::class, 1);
        $resource = notifySentDigest()->digest['resources'][0];
        expect($resource['name'])->toBe('api')
            ->and($resource['uuid'])->toBe($this->application->uuid)
            ->and($resource['counts'])->toBe(['critical' => 2])
            ->and($resource['worst'])->toBe('critical')
            ->and($resource['ids'])->toBe(['CVE-2026-0002', 'CVE-2026-0003'])
            ->and($resource['url'])->toBe("https://coolify.example.com/project/{$this->environment->project->uuid}/environment/{$this->environment->uuid}/application/{$this->application->uuid}/vulnerabilities")
            ->and(notifyStateOf($this->application)->notified_ids)->toBe(['CVE-2026-0001', 'CVE-2026-0002', 'CVE-2026-0003']);
    });

    it('sends one digest for the team when 20 resources have the same new vulnerability', function () {
        foreach (range(1, 20) as $index) {
            notifyScan(notifyApplication("app-{$index}"), [notifyFinding('CVE-2026-0100')]);
        }

        notifyRunDigest();

        Notification::assertSentToTimes($this->team, VulnerabilityDigest::class, 1);
        expect(notifySentDigest()->digest['resources'])->toHaveCount(20);
    });

    it('notifies again when a fixed vulnerability comes back', function () {
        $scan = notifyScan($this->application, [notifyFinding('CVE-2026-0009', ['fixState' => 'not-fixed', 'fixedIn' => null])]);
        notifyState($this->application, ['CVE-2026-0001']);

        notifyRunDigest();
        Notification::assertNothingSent();
        expect(notifyStateOf($this->application)->notified_ids)->toBe([]);

        $scan->update(['findings' => [notifyFinding('CVE-2026-0001')]]);
        notifyRunDigest();

        Notification::assertSentToTimes($this->team, VulnerabilityDigest::class, 1);
        expect(notifySentDigest()->digest['resources'][0]['ids'])->toBe(['CVE-2026-0001']);
    });

    it('keeps notified IDs while a deployment of the application runs', function () {
        notifyScan($this->application, []);
        notifyState($this->application, ['CVE-2026-0001']);
        notifyDeployment($this->application)->update(['status' => ApplicationDeploymentStatus::IN_PROGRESS->value]);

        notifyRunDigest();

        expect(notifyStateOf($this->application)->notified_ids)->toBe(['CVE-2026-0001']);
    });

    it('matches notified IDs by alias and skips ignored findings', function () {
        notifyScan($this->application, [
            notifyFinding('CVE-2026-0001', ['aliases' => ['GHSA-aaaa-bbbb-cccc']]),
            notifyFinding('CVE-2026-0002'),
        ]);
        notifyState($this->application, ['GHSA-aaaa-bbbb-cccc']);
        ImageScanIgnore::create(['team_id' => $this->team->id, 'vulnerability_id' => 'CVE-2026-0002', 'reason' => 'Not used.']);

        notifyRunDigest();

        Notification::assertNothingSent();
    });

    it('sends no digest when nothing changed', function () {
        notifyScan($this->application, [notifyFinding('CVE-2026-0001')]);
        notifyState($this->application, ['CVE-2026-0001']);

        notifyRunDigest();

        Notification::assertNothingSent();
    });

    it('never notifies for a preview scan and leaves the state alone', function () {
        notifyScan($this->application, [notifyFinding('CVE-2026-0001')]);
        notifyState($this->application, ['CVE-2026-0001']);
        $preview = notifyDeployment($this->application, 7);
        $scan = notifyScan($this->application, [notifyFinding('CVE-2026-0009', ['kev' => true])], ['deployment_queue_id' => $preview->id]);

        ScanNotifications::afterScan($scan, $preview->id);
        notifyRunDigest();

        Notification::assertNothingSent();
        expect(notifyStateOf($this->application)->notified_ids)->toBe(['CVE-2026-0001']);
    });

    it('does not report a released fix again after a redeploy to a new image', function () {
        notifyScan($this->application, [], ['candidates' => notifyCandidates(), 'scanned_at' => now()->subDay()]);
        notifyRunDigest();
        Notification::assertSentToTimes($this->team, VulnerabilityDigest::class, 1);

        $deployment = notifyDeployment($this->application);
        $scan = notifyScan($this->application, [], ['deployment_queue_id' => $deployment->id, 'candidates' => notifyCandidates()]);
        ScanNotifications::afterScan($scan, $deployment->id);
        notifyRunDigest();

        Notification::assertSentToTimes($this->team, VulnerabilityDigest::class, 1);
    });

    it('keeps the IDs of a known exploited alert that comes while the digest runs', function () {
        $scan = notifyScan($this->application, [notifyFinding('CVE-2026-0001'), notifyFinding('CVE-2026-0002')]);
        notifyState($this->application, ['CVE-2026-0001']);
        $plan = ScanNotifications::digestPlan($this->application);

        $scan->update(['findings' => [...$scan->findings, notifyFinding('CVE-2026-0003', ['kev' => true])]]);
        ScanNotifications::afterScan($scan->fresh());
        ScanNotifications::applyDigest($plan['mark']);

        Notification::assertSentToTimes($this->team, KnownExploitedFound::class, 1);
        expect(notifyStateOf($this->application)->notified_ids)->toEqualCanonicalizing(['CVE-2026-0001', 'CVE-2026-0002', 'CVE-2026-0003']);
    });

    it('does not record a baseline plan when the baseline ended while the digest ran', function () {
        $first = notifyDeployment($this->application);
        notifyScan($this->application, [notifyFinding('CVE-2026-0001')], ['deployment_queue_id' => $first->id, 'scanned_at' => now()->subHour()], state: false);
        notifyState($this->application, [], baseline: true, deploymentId: $first->id);
        $plan = ScanNotifications::digestPlan($this->application);

        $second = notifyDeployment($this->application);
        $redeploy = notifyScan($this->application, [notifyFinding('CVE-2026-0001'), notifyFinding('CVE-2026-0002')], ['deployment_queue_id' => $second->id, 'image_id' => 'sha256:'.str_repeat('e', 64)], state: false);
        ScanNotifications::afterScan($redeploy, $second->id);
        ScanNotifications::applyDigest($plan['mark']);
        notifyRunDigest();

        Notification::assertSentToTimes($this->team, VulnerabilityDigest::class, 1);
        expect(notifySentDigest()->digest['resources'][0]['ids'])->toBe(['CVE-2026-0001', 'CVE-2026-0002']);
    });

    it('records only the checked scan when a candidate check continues an open baseline', function () {
        $first = notifyDeployment($this->application);
        $scan = notifyScan($this->application, [notifyFinding('CVE-2026-0001')], ['deployment_queue_id' => $first->id, 'scanned_at' => now()->subHour()], state: false);
        ScanNotifications::afterScan($scan, $first->id);
        $second = notifyDeployment($this->application);
        // The redeploy wrote its scan; its own notification runs after the candidate check below.
        $redeploy = notifyScan($this->application, [notifyFinding('CVE-2026-0002')], ['deployment_queue_id' => $second->id, 'image_id' => 'sha256:'.str_repeat('e', 64)], state: false);

        $scan->update(['candidates' => notifyCandidates()]);
        ScanNotifications::afterScan($scan->fresh(), candidateCheck: true);
        expect(notifyStateOf($this->application)->notified_ids)->toBe(['CVE-2026-0001']);

        ScanNotifications::afterScan($redeploy, $second->id);
        notifyRunDigest();

        expect(notifySentDigest()->digest['resources'][0]['ids'])->toBe(['CVE-2026-0002']);
    });

    it('keeps the notified candidates while a candidate check has not run yet', function () {
        $key = 'nginx:1.28@sha256:'.str_repeat('b', 64);
        notifyScan($this->application, [notifyFinding('CVE-2026-0001')], ['candidates' => null]);
        notifyState($this->application, ['CVE-2026-0001'], [$key]);

        notifyRunDigest();

        expect(notifyStateOf($this->application)->notified_candidates)->toBe([$key]);
    });

    it('records nothing for a resource whose digest failed', function () {
        notifyScan($this->application, [notifyFinding('CVE-2026-0001')]);
        notifyState($this->application, []);
        // The team page link works; the link of the digest line fails after the plan of the resource was made.
        $calls = 0;
        URL::partialMock()->shouldReceive('route')->andReturnUsing(function () use (&$calls) {
            if (++$calls > 1) {
                throw new RuntimeException('no route');
            }

            return 'https://coolify.example.com/vulnerabilities';
        });
        app()->instance(ExceptionHandler::class, Mockery::mock(ExceptionHandler::class)->shouldIgnoreMissing());

        notifyRunDigest();

        expect(notifyStateOf($this->application)->notified_ids)->toBe([]);
    });

    it('deletes the state with the resource', function () {
        notifyState($this->application, ['CVE-2026-0001']);

        $this->application->delete();

        expect(ImageScanNotificationState::query()->count())->toBe(0);
    });
});

describe('known exploited', function () {
    it('alerts right away for a new known exploited finding and leaves it out of the digest', function () {
        $scan = notifyScan($this->application, [notifyFinding('CVE-2026-0001', ['kev' => true]), notifyFinding('CVE-2026-0002', ['kev' => true, 'severity' => 'low'])]);

        ScanNotifications::afterScan($scan);
        ScanNotifications::afterScan($scan->fresh());
        notifyRunDigest();

        Notification::assertSentToTimes($this->team, KnownExploitedFound::class, 1);
        // A finding in CISA KEV is relevant at any severity (spec 15.2).
        Notification::assertSentTo($this->team, KnownExploitedFound::class, fn (KnownExploitedFound $notification) => $notification->count === 2
            && $notification->resourceName === 'api'
            && $notification->worst === 'critical'
            && $notification->ids === ['CVE-2026-0001', 'CVE-2026-0002']);
        Notification::assertNotSentTo($this->team, VulnerabilityDigest::class);
    });

    it('uses the image scan thread on Telegram', function () {
        $this->team->telegramNotificationSettings->update([
            'telegram_enabled' => true,
            'telegram_token' => '123:abc',
            'telegram_chat_id' => '42',
            'image_vulnerabilities_telegram_notifications' => true,
            'telegram_notifications_image_vulnerabilities_thread_id' => '77',
        ]);
        Bus::fake();
        $scan = notifyScan($this->application, [notifyFinding('CVE-2026-0001', ['kev' => true])]);

        (new TelegramChannel)->send($this->team->fresh(), new KnownExploitedFound($scan, 1));

        Bus::assertDispatched(SendMessageToTelegramJob::class, fn ($job) => $job->threadId === '77');
    });
});

describe('digest sections', function () {
    it('lists ignores of the team that end within 7 days once', function () {
        ImageScanIgnore::create(['team_id' => $this->team->id, 'vulnerability_id' => 'CVE-2026-0300', 'reason' => 'Later.', 'expires_at' => now()->addDays(3)]);
        ImageScanIgnore::create(['team_id' => $this->team->id, 'package' => 'zlib', 'reason' => 'Later.', 'expires_at' => now()->addDays(20)]);
        ImageScanIgnore::create(['team_id' => $this->team->id, 'package' => 'curl', 'reason' => 'Old.', 'expires_at' => now()->subDay()]);
        $otherTeam = Team::factory()->create();
        ImageScanIgnore::create(['team_id' => $otherTeam->id, 'vulnerability_id' => 'CVE-2026-0301', 'reason' => 'Other.', 'expires_at' => now()->addDays(2)]);

        notifyRunDigest();
        notifyRunDigest();

        Notification::assertSentToTimes($this->team, VulnerabilityDigest::class, 1);
        $ignores = notifySentDigest()->digest['ignores'];
        expect($ignores)->toHaveCount(1)
            ->and($ignores[0]['id'])->toBe('CVE-2026-0300')
            ->and($ignores[0]['resource'])->toBeNull();
    });

    it('reports a server whose rescans failed 2 days in a row once', function () {
        Carbon::setTestNow('2026-10-20 03:00:00');
        ImageRescanJob::recordRescanResult($this->server, false);
        ImageRescanJob::recordRescanResult($this->server, false);
        notifyRunDigest();
        Notification::assertNothingSent();

        Carbon::setTestNow('2026-10-21 03:00:00');
        ImageRescanJob::recordRescanResult($this->server, false);
        notifyRunDigest();
        notifyRunDigest();

        Notification::assertSentToTimes($this->team, VulnerabilityDigest::class, 1);
        expect(notifySentDigest()->digest['servers'])->toBe([[
            'name' => $this->server->name,
            'url' => "https://coolify.example.com/server/{$this->server->uuid}",
        ]]);

        ImageRescanJob::recordRescanResult($this->server, true);
        expect(ImageRescanJob::failedDays($this->server))->toBe(0);
        Carbon::setTestNow();
    });

    it('does not report a failing server whose scanning is off now', function () {
        Carbon::setTestNow('2026-10-20 03:00:00');
        ImageRescanJob::recordRescanResult($this->server, false);
        Carbon::setTestNow('2026-10-21 03:00:00');
        ImageRescanJob::recordRescanResult($this->server, false);
        $this->server->settings->forceFill(['is_image_scan_enabled' => false])->saveQuietly();

        notifyRunDigest();

        Notification::assertNothingSent();
        Carbon::setTestNow();
    });

    it('reports a newly released fix once', function () {
        notifyScan($this->application, [], [
            'candidates' => ['status' => 'ok', 'checked_at' => now()->toIso8601String(), 'entries' => [[
                'ref' => 'nginx:1.28', 'digest' => 'sha256:'.str_repeat('b', 64), 'kind' => 'line', 'better' => true,
                'fixed_ids' => ['CVE-2026-0001'], 'fixes' => ['critical' => 1], 'left' => [],
            ]]],
        ]);

        notifyRunDigest();
        notifyRunDigest();

        Notification::assertSentToTimes($this->team, VulnerabilityDigest::class, 1);
        expect(notifySentDigest()->digest['resources'][0]['updates'])->toBe(['Update nginx:1.27 to 1.28.']);
    });

    it('names the recommended fix of a resource with new findings', function () {
        notifyState($this->application, [], ['nginx:1.28@sha256:'.str_repeat('b', 64)]);
        notifyScan($this->application, [notifyFinding('CVE-2026-0001')], [
            'candidates' => ['status' => 'ok', 'checked_at' => now()->toIso8601String(), 'entries' => [[
                'ref' => 'nginx:1.28', 'digest' => 'sha256:'.str_repeat('b', 64), 'kind' => 'line', 'better' => true,
                'fixed_ids' => ['CVE-2026-0001'], 'fixes' => ['critical' => 1], 'left' => [],
            ]]],
        ]);

        notifyRunDigest();

        $resource = notifySentDigest()->digest['resources'][0];
        expect($resource['fix'])->toBe('Update nginx:1.27 to 1.28.')
            ->and($resource['updates'])->toBe([]);
    });

    it('only covers scanned resources of its own team', function () {
        $otherTeam = Team::factory()->create();
        notifyEnableDiscord($otherTeam);
        [, $otherDestination, $otherEnvironment] = notifyTeamSetup($otherTeam);
        notifyScan(notifyApplication('other', $otherEnvironment, $otherDestination), [notifyFinding('CVE-2026-0001')]);
        $off = notifyApplication('not-scanned');
        $off->settings->forceFill(['is_image_scan_enabled' => false])->saveQuietly();
        notifyScan($off, [notifyFinding('CVE-2026-0001')]);

        notifyRunDigest();

        Notification::assertNothingSent();
    });
});

describe('content', function () {
    it('puts no vulnerability IDs in chat and webhook payloads', function () {
        notifyScan($this->application, [
            notifyFinding('CVE-2026-0001', ['kev' => true]),
            notifyFinding('GHSA-aaaa-bbbb-cccc'),
        ]);
        ImageScanIgnore::create(['team_id' => $this->team->id, 'vulnerability_id' => 'CVE-2026-0300', 'reason' => 'Later.', 'expires_at' => now()->addDays(3)]);
        $scan = ImageScan::query()->sole();

        ScanNotifications::afterScan($scan);
        $scan->update(['findings' => [...$scan->findings, notifyFinding('CVE-2026-0002')]]);
        notifyRunDigest();

        $known = null;
        Notification::assertSentTo($this->team, KnownExploitedFound::class, function (KnownExploitedFound $notification) use (&$known) {
            $known = $notification;

            return true;
        });

        foreach ([notifySentDigest(), $known] as $notification) {
            foreach (notifyChatPayloads($notification) as $channel => $payload) {
                expect($payload)->not->toContain('CVE-', "{$channel} payload")
                    ->and($payload)->not->toContain('GHSA-', "{$channel} payload");
            }
        }
    });

    it('fits every chat channel in its length limit', function () {
        $digest = new VulnerabilityDigest([
            'resources' => array_map(notifyLongResource(...), range(1, 60)),
            'ignores' => [['resource' => null, 'id' => 'CVE-2026-0300', 'package' => null, 'expires_at' => '2026-10-30']],
            'servers' => [['name' => 'server', 'url' => 'https://coolify.example.com/server/s']],
        ]);

        $discord = $digest->toDiscord()->toPayload()['embeds'][0]['description'];
        $telegram = $digest->toTelegram()['message'];
        $slack = $digest->toSlack()->description;
        $pushover = $digest->toPushover()->toPayload('token', 'user')['message'];

        expect(mb_strlen($discord))->toBeLessThanOrEqual(4096)->toBeGreaterThan(3000)
            ->and(mb_strlen($telegram))->toBeLessThanOrEqual(4096)->toBeGreaterThan(3000)
            ->and(mb_strlen($slack))->toBeLessThanOrEqual(3000)->toBeGreaterThan(2000)
            ->and(mb_strlen($pushover))->toBeLessThanOrEqual(1024);
        foreach ([$discord, $telegram, $slack, $pushover] as $text) {
            expect($text)->toMatch('/And \d+ more\./');
        }
        expect(count($digest->toWebhook()['resources']))->toBe(60);
    });

    it('fits the known exploited alert in the channel limits and keeps only values', function () {
        $this->application->update(['name' => str_repeat('&', 100)]);
        $scan = notifyScan($this->application, [notifyFinding('CVE-2026-0001', ['kev' => true]), notifyFinding('CVE-2026-0002', ['kev' => true])], [
            'image_ref' => 'ghcr.io/'.str_repeat('a', 300).':1.0',
        ]);
        $notification = new KnownExploitedFound($scan, 1, ['CVE-2026-0002']);
        $scan->delete();

        $notification = unserialize(serialize($notification));
        $mail = (string) $notification->toMail($this->team)->render();

        expect(mb_strlen($notification->toPushover()->toPayload('token', 'user')['message']))->toBeLessThanOrEqual(1024)
            ->and(mb_strlen($notification->toDiscord()->toPayload()['embeds'][0]['description']))->toBeLessThanOrEqual(4096)
            ->and($mail)->toContain('CVE-2026-0002')
            ->and($mail)->not->toContain('CVE-2026-0001');
    });

    it('lists at most 10 IDs in the email', function () {
        notifyScan($this->application, array_map(fn (int $n) => notifyFinding(sprintf('CVE-2026-%04d', $n)), range(1, 15)));

        notifyRunDigest();

        $html = (string) notifySentDigest()->toMail($this->team)->render();
        expect(substr_count($html, 'CVE-2026-'))->toBe(10)
            ->and($html)->toContain('15 critical')
            ->and($html)->toContain('https://coolify.example.com/project/');
    });

    it('escapes resource names per channel', function () {
        $this->application->update(['name' => '*bold* & [link](https://evil.example)']);
        notifyScan($this->application, [notifyFinding('CVE-2026-0001')]);

        notifyRunDigest();

        $digest = notifySentDigest();
        $mail = (string) $digest->toMail($this->team)->render();
        expect($digest->toDiscord()->toPayload()['embeds'][0]['description'])->toContain('[\\*bold\\* & \\[link\\]\\(https://evil.example\\)](https://coolify.example.com/')
            ->and($digest->toSlack()->description)->toContain('|*bold* &amp; [link](https://evil.example)>')
            ->and($digest->toPushover()->toPayload('token', 'user')['message'])->toContain('>*bold* &amp; [link](https://evil.example)</a>')
            ->and($mail)->not->toContain('<em>bold</em>')
            ->and($mail)->not->toContain('href="https://evil.example"');
    });

    it('sends the email only when the image vulnerabilities email event is on', function () {
        $this->team->emailNotificationSettings->update(['smtp_enabled' => true, 'image_vulnerabilities_email_notifications' => false]);
        $digest = new VulnerabilityDigest(['resources' => [], 'ignores' => [], 'servers' => []]);

        expect($digest->via($this->team->fresh()))->not->toContain(EmailChannel::class);

        $this->team->emailNotificationSettings->update(['image_vulnerabilities_email_notifications' => true]);

        expect($digest->via($this->team->fresh()))->toContain(EmailChannel::class);
    });

    it('turns the email event on and the chat and webhook events off by default', function () {
        $team = Team::factory()->create();

        expect($team->emailNotificationSettings->fresh()->image_vulnerabilities_email_notifications)->toBeTrue()
            ->and($team->discordNotificationSettings->fresh()->image_vulnerabilities_discord_notifications)->toBeFalse()
            ->and($team->slackNotificationSettings->fresh()->image_vulnerabilities_slack_notifications)->toBeFalse()
            ->and($team->telegramNotificationSettings->fresh()->image_vulnerabilities_telegram_notifications)->toBeFalse()
            ->and($team->pushoverNotificationSettings->fresh()->image_vulnerabilities_pushover_notifications)->toBeFalse()
            ->and($team->webhookNotificationSettings->fresh()->image_vulnerabilities_webhook_notifications)->toBeFalse();
    });
});

describe('blocked deploys', function () {
    it('titles a blocked deploy with counts in email and without counts in chat and webhook channels', function () {
        $deployment = ApplicationDeploymentQueue::create([
            'application_id' => $this->application->id,
            'deployment_uuid' => (string) Str::uuid(),
            'server_id' => $this->server->id,
            'server_name' => $this->server->name,
            'destination_id' => $this->application->destination_id,
            'pull_request_id' => 0,
            'status' => ApplicationDeploymentStatus::FAILED->value,
            'scan_gate_result' => [
                'blocked' => true, 'scan_ran' => true, 'error' => null, 'count' => 2,
                'headline' => 'Deploy blocked: this image has 2 security problems that attackers are using.',
                'headline_plain' => 'Deploy blocked: this image has security problems that attackers are using.',
                'top' => [['id' => 'CVE-2026-1234', 'severity' => 'critical', 'package' => 'openssl', 'version' => '3.0.13', 'fixed_in' => '3.0.15', 'kev' => false]],
            ],
        ]);

        $notification = new DeploymentFailed($this->application, $deployment->deployment_uuid, null, $deployment);

        expect($notification->toDiscord()->title)->toBe('Deploy blocked: this image has security problems that attackers are using')
            ->and($notification->toSlack()->title)->toStartWith('Deploy blocked: this image has security problems')
            ->and($notification->toPushover()->title)->toStartWith('Deploy blocked: this image has security problems')
            ->and($notification->toTelegram()['message'])->toContain('Deploy blocked: this image has security problems that attackers are using')
            ->and($notification->toWebhook()['message'])->toBe('Deploy blocked: this image has security problems that attackers are using')
            ->and($notification->toMail($this->team)->subject)->toContain('Deploy blocked: this image has 2 security problems that attackers are using');
        foreach (notifyChatPayloads($notification) as $payload) {
            expect($payload)->not->toContain('CVE-')
                ->and(preg_match('/\d+ security problems?/', $payload))->toBe(0);
        }
        expect((string) $notification->toMail($this->team)->render())->not->toContain('CVE-');
    });

    it('leaves the counts out of chat titles of a result stored without a plain headline', function () {
        $deployment = ApplicationDeploymentQueue::create([
            'application_id' => $this->application->id,
            'deployment_uuid' => (string) Str::uuid(),
            'server_id' => $this->server->id,
            'server_name' => $this->server->name,
            'destination_id' => $this->application->destination_id,
            'pull_request_id' => 0,
            'status' => ApplicationDeploymentStatus::FAILED->value,
            'scan_gate_result' => ['blocked' => true, 'scan_ran' => true, 'error' => null, 'count' => 2, 'headline' => 'Deploy blocked: 2 critical vulnerabilities with a fix.'],
        ]);

        $notification = new DeploymentFailed($this->application, $deployment->deployment_uuid, null, $deployment);

        expect($notification->toWebhook()['message'])->toBe('Deploy blocked by the image scan')
            ->and($notification->toMail($this->team)->subject)->toContain('Deploy blocked: 2 critical vulnerabilities with a fix');
    });

    it('keeps the deployment failed title when the gate did not block', function () {
        $deployment = ApplicationDeploymentQueue::create([
            'application_id' => $this->application->id,
            'deployment_uuid' => (string) Str::uuid(),
            'server_id' => $this->server->id,
            'server_name' => $this->server->name,
            'destination_id' => $this->application->destination_id,
            'pull_request_id' => 0,
            'status' => ApplicationDeploymentStatus::FAILED->value,
        ]);

        $notification = new DeploymentFailed($this->application, $deployment->deployment_uuid, null, $deployment);

        expect($notification->toDiscord()->title)->toContain('Deployment failed')
            ->and($notification->toPushover()->title)->toBe('Deployment failed');
    });
});

describe('settings', function () {
    it('saves the image vulnerabilities event of every channel', function (string $component, string $relation, string $channel) {
        $user = User::factory()->create();
        $user->teams()->attach($this->team, ['role' => 'owner']);
        session(['currentTeam' => $this->team]);
        $this->actingAs($user);
        $property = 'imageVulnerabilities'.ucfirst($channel).'Notifications';
        $column = "image_vulnerabilities_{$channel}_notifications";
        $before = (bool) $this->team->{$relation}->fresh()->{$column};

        Livewire::test($component)->call('toggleEvent', $property)->assertSet($property, ! $before);

        expect((bool) $this->team->{$relation}->fresh()->{$column})->toBe(! $before);

        Cache::flush();
        Livewire::test($component)->assertSet($property, ! $before);
    })->with([
        'email' => [Email::class, 'emailNotificationSettings', 'email'],
        'discord' => [Discord::class, 'discordNotificationSettings', 'discord'],
        'slack' => [Slack::class, 'slackNotificationSettings', 'slack'],
        'telegram' => [Telegram::class, 'telegramNotificationSettings', 'telegram'],
        'pushover' => [Pushover::class, 'pushoverNotificationSettings', 'pushover'],
        'webhook' => [Webhook::class, 'webhookNotificationSettings', 'webhook'],
    ]);
});

describe('schedule', function () {
    it('gives each team a daily digest slot between 06:00 and 07:59', function () {
        expect(ServerManagerJob::imageScanDigestCron(7))->toBe('7 6 * * *')
            ->and(ServerManagerJob::imageScanDigestCron(61))->toBe('1 7 * * *')
            ->and(ServerManagerJob::imageScanDigestCron(120))->toBe('0 6 * * *');
    });

    it('dispatches the digest once per team per day', function () {
        Queue::fake();
        config(['constants.coolify.self_hosted' => true]);
        InstanceSettings::query()->whereKey(0)->update(['instance_timezone' => 'UTC']);
        $otherTeam = Team::factory()->create();
        $otherServer = Server::factory()->create(['team_id' => $otherTeam->id]);
        $otherServer->settings->forceFill(['is_image_scan_enabled' => false])->saveQuietly();
        [$minute, $hour] = array_map('intval', explode(' ', ServerManagerJob::imageScanDigestCron($this->team->id)));
        $slot = Carbon::parse('2026-10-27 00:00:00', 'UTC')->setTime($hour, $minute);
        $run = function (Carbon $at) {
            Carbon::setTestNow($at);
            Server::flushIdentityMap();
            (new ServerManagerJob)->handle();
        };

        $run($slot->copy()->subMinute());
        Queue::assertNotPushed(ImageScanDigestJob::class);

        $run($slot);
        $run($slot->copy()->addMinute());
        Queue::assertPushed(ImageScanDigestJob::class, 1);
        Queue::assertPushed(ImageScanDigestJob::class, fn (ImageScanDigestJob $job) => $job->team->is($this->team));

        $run($slot->copy()->addDay());
        Queue::assertPushed(ImageScanDigestJob::class, 2);
        Carbon::setTestNow();
    });
});
