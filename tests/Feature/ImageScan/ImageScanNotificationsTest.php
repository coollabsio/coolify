<?php

use App\Enums\ApplicationDeploymentStatus;
use App\Jobs\ImageRescanJob;
use App\Jobs\ImageScanAlertCheckJob;
use App\Jobs\ImageScanDigestJob;
use App\Jobs\RefreshCandidatesJob;
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
use App\Notifications\ImageScan\ExploitedProblemFound;
use App\Notifications\ImageScan\VulnerabilityDigest;
use App\Services\ImageScan\ResourceScans;
use App\Services\ImageScan\ScanNotifications;
use Illuminate\Contracts\Bus\Dispatcher;
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
 * A critical OS package finding with a fix and no exploit signal. In the Docker image app of these tests it gets the
 * action "Rebuild the image in your CI, then redeploy." (spec 15.5).
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function notifyFinding(string $id, array $overrides = []): array
{
    return [
        'id' => $id, 'aliases' => [], 'severity' => 'critical', 'package' => 'openssl', 'version' => '3.0.1',
        'type' => 'apk', 'artifactId' => 'a-'.$id, 'fixedIn' => '3.0.2', 'fixState' => 'fixed', 'kev' => false,
        'epss' => 0.01, 'risk' => 1.0, 'summary' => 'A bug.', 'layer' => 'app', 'title' => "The bug {$id} lets attackers read memory",
        ...$overrides,
    ];
}

/**
 * A finding that attackers are using (CISA KEV), with a fix.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function notifyExploited(string $id, array $overrides = []): array
{
    return notifyFinding($id, ['kev' => true, ...$overrides]);
}

/**
 * A finding with an exploit signal and no fix.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function notifyExploitedWithoutFix(string $id, array $overrides = []): array
{
    return notifyExploited($id, ['fixState' => 'not-fixed', 'fixedIn' => null, ...$overrides]);
}

/**
 * A completed scan with its stored security status and a finished version check. Unless `$state` is false, the
 * resource also gets a state row that is not a baseline and has nothing notified yet (when it has none).
 *
 * @param  list<array<string, mixed>>  $findings
 * @param  array<string, mixed>  $attributes
 */
function notifyScan(Model $resource, array $findings, array $attributes = [], bool $state = true): ImageScan
{
    if ($state && notifyStateOf($resource) === null) {
        notifyState($resource, []);
    }

    $scan = ImageScan::factory()->create([
        'server_id' => test()->server->id,
        'resource_type' => $resource->getMorphClass(),
        'resource_id' => $resource->getKey(),
        'image_ref' => 'nginx:1.27',
        'builds_image' => true,
        'findings' => $findings,
        'summary' => [],
        'candidates' => ['status' => 'no_better', 'checked_at' => now()->toIso8601String(), 'error' => null, 'entries' => []],
        'is_baseline' => false,
        ...$attributes,
    ]);

    return notifyRefresh($scan);
}

/**
 * Changes a scan and stores its security status again, like a rescan or a version check does.
 *
 * @param  array<string, mixed>  $attributes
 */
function notifyRefresh(ImageScan $scan, array $attributes = []): ImageScan
{
    $scan->forceFill($attributes)->save();
    $scan = $scan->fresh();
    ResourceScans::refreshSummaries(collect([$scan]));

    return $scan->fresh();
}

/**
 * @param  list<string>  $ids
 * @param  list<string>  $candidates
 * @param  list<string>  $digestIds
 */
function notifyState(Model $resource, array $ids, array $candidates = [], bool $baseline = false, ?int $deploymentId = null, array $digestIds = []): ImageScanNotificationState
{
    return ImageScanNotificationState::query()->updateOrCreate(
        ['resource_type' => $resource->getMorphClass(), 'resource_id' => $resource->getKey()],
        ['team_id' => test()->team->id, 'notified_ids' => $ids, 'notified_candidates' => $candidates, 'digest_ids' => $digestIds, 'is_baseline' => $baseline, 'baseline_deployment_queue_id' => $deploymentId],
    );
}

function notifyStateOf(Model $resource): ?ImageScanNotificationState
{
    return ImageScanNotificationState::query()->where('resource_type', $resource->getMorphClass())->where('resource_id', $resource->getKey())->first();
}

/**
 * @return array<string, mixed>
 */
function notifyCandidates(string $ref = 'nginx:1.28', array $fixedIds = ['CVE-2026-0001']): array
{
    return ['status' => 'ok', 'checked_at' => now()->toIso8601String(), 'entries' => [[
        'ref' => $ref, 'digest' => 'sha256:'.str_repeat('b', 64), 'kind' => 'line', 'better' => true,
        'fixed_ids' => $fixedIds, 'fixes' => ['critical' => 1], 'left' => [],
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
        'status' => 'update_available', 'badge' => 'Security update', 'action' => 'Change the base image to ghcr.io/'.str_repeat('a', 150).':1.0 in your Dockerfile, then redeploy.',
        'why' => [],
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

function notifySentAlert(?Team $team = null): ExploitedProblemFound
{
    $sent = null;
    Notification::assertSentTo($team ?? test()->team, ExploitedProblemFound::class, function (ExploitedProblemFound $notification) use (&$sent) {
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

/**
 * A count of problems or vulnerabilities in a text: "2 problems", "3 critical", "1 known exploited".
 */
function notifyCountPattern(): string
{
    return '/\b\d+\s+(security\s+)?(problems?|vulnerabilit(y|ies)|critical|high|medium|low|known)\b/i';
}

describe('notification state', function () {
    it('creates a baseline for the first deploy of an image and sends nothing', function () {
        $deployment = notifyDeployment($this->application);
        $scan = notifyScan($this->application, [notifyExploited('CVE-2026-0001'), notifyFinding('CVE-2026-0002'), notifyFinding('CVE-2026-0003', ['fixState' => 'not-fixed', 'fixedIn' => null])], [
            'is_baseline' => true, 'deployment_queue_id' => $deployment->id,
        ], state: false);

        ScanNotifications::afterScan($scan, $deployment->id);
        notifyRunDigest();

        Notification::assertNothingSent();
        $state = notifyStateOf($this->application);
        expect($state->notified_ids)->toBe(['CVE-2026-0001'])
            ->and($state->digest_ids)->toBe(['CVE-2026-0001', 'CVE-2026-0002'])
            ->and($state->is_baseline)->toBeFalse();
    });

    it('treats both images of a first Compose deployment as a baseline', function () {
        $deployment = notifyDeployment($this->application);
        $web = notifyScan($this->application, [notifyFinding('CVE-2026-0001')], ['is_baseline' => true, 'deployment_queue_id' => $deployment->id, 'image_ref' => 'web:1'], state: false);
        ScanNotifications::afterScan($web, $deployment->id);
        $worker = notifyScan($this->application, [notifyExploited('CVE-2026-0002')], ['deployment_queue_id' => $deployment->id, 'image_ref' => 'worker:1'], state: false);
        ScanNotifications::afterScan($worker, $deployment->id);
        $worker = notifyRefresh($worker, ['candidates' => notifyCandidates('worker:2', ['CVE-2026-0002'])]);
        ScanNotifications::afterScan($worker, candidateCheck: true);

        notifyRunDigest();
        notifyRunDigest();

        Notification::assertNothingSent();
        expect(notifyStateOf($this->application)->notified_ids)->toBe(['CVE-2026-0002'])
            ->and(notifyStateOf($this->application)->digest_ids)->toEqualCanonicalizing(['CVE-2026-0001', 'CVE-2026-0002']);
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
        expect(notifySentDigest()->digest['resources'][0]['status'])->toBe('update_available')
            ->and(notifyStateOf($this->application)->digest_ids)->toEqualCanonicalizing(['CVE-2026-0001', 'CVE-2026-0002']);
    });

    it('alerts right away for a new Compose service image with an exploited finding, in any order', function (array $order) {
        $this->application->update(['build_pack' => 'dockercompose']);
        $old = notifyDeployment($this->application);
        $rows = [
            'web' => notifyScan($this->application, [notifyFinding('CVE-2026-0001')], ['deployment_queue_id' => $old->id, 'image_ref' => 'web:1', 'scanned_at' => now()->subDay()], state: false),
            'worker' => notifyScan($this->application, [notifyExploited('CVE-2026-0002')], ['deployment_queue_id' => $old->id, 'image_ref' => 'worker:1', 'scanned_at' => now()->subDay()], state: false),
        ];
        notifyState($this->application, ['CVE-2026-0002'], digestIds: ['CVE-2026-0001', 'CVE-2026-0002']);
        $new = notifyDeployment($this->application);
        // The image of the new Compose service, before the deploy scan writes its results.
        $rows['cache'] = notifyScan($this->application, [notifyExploited('CVE-2026-0003')], ['image_ref' => 'cache:1', 'scanned_at' => now()->subDays(5)], state: false);

        foreach ($order as $name) {
            // The deploy rescans reused rows, which then belong to the new deployment.
            $rows[$name] = notifyRefresh($rows[$name], ['deployment_queue_id' => $new->id, 'scanned_at' => now()]);
            ScanNotifications::afterScan($rows[$name], $new->id);
            $this->travel(1)->seconds();
        }
        notifyRunDigest();

        Notification::assertSentToTimes($this->team, ExploitedProblemFound::class, 1);
        expect(array_column(notifySentAlert()->why, 'display_id'))->toBe(['CVE-2026-0003']);
        Notification::assertNotSentTo($this->team, VulnerabilityDigest::class);
    })->with([
        'new image first' => [['cache', 'web', 'worker']],
        'new image last' => [['web', 'worker', 'cache']],
    ]);

    it('does not notify again when unchanged images are rescanned in any order', function (array $order) {
        $this->application->update(['build_pack' => 'dockercompose']);
        $old = notifyDeployment($this->application);
        $rows = [
            'web' => notifyScan($this->application, [notifyFinding('CVE-2026-0001')], ['deployment_queue_id' => $old->id, 'image_ref' => 'web:1', 'scanned_at' => now()->subDay()], state: false),
            'worker' => notifyScan($this->application, [notifyExploited('CVE-2026-0002')], ['deployment_queue_id' => $old->id, 'image_ref' => 'worker:1', 'scanned_at' => now()->subDay()], state: false),
        ];
        notifyState($this->application, ['CVE-2026-0002'], digestIds: ['CVE-2026-0001', 'CVE-2026-0002']);
        $new = notifyDeployment($this->application);

        foreach ($order as $name) {
            $rows[$name] = notifyRefresh($rows[$name], ['deployment_queue_id' => $new->id, 'scanned_at' => now()]);
            ScanNotifications::afterScan($rows[$name], $new->id);
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

    it('sends one digest line for new problems with an update on the next run, not two', function () {
        notifyScan($this->application, [
            notifyFinding('CVE-2026-0001'), notifyFinding('CVE-2026-0002'), notifyFinding('CVE-2026-0003'),
            notifyFinding('CVE-2026-0004', ['severity' => 'low']),
        ]);
        notifyState($this->application, [], digestIds: ['CVE-2026-0001']);

        notifyRunDigest();
        notifyRunDigest();

        Notification::assertSentToTimes($this->team, VulnerabilityDigest::class, 1);
        expect(notifySentDigest()->digest['resources'])->toBe([[
            'name' => 'api',
            'uuid' => $this->application->uuid,
            'url' => "https://coolify.example.com/project/{$this->environment->project->uuid}/environment/{$this->environment->uuid}/application/{$this->application->uuid}/vulnerabilities",
            'status' => 'update_available',
            'badge' => 'Security update',
            'action' => 'Rebuild the image in your CI, then redeploy.',
            'why' => notifySentDigest()->digest['resources'][0]['why'],
        ]])
            ->and(notifyStateOf($this->application)->digest_ids)->toBe(['CVE-2026-0001', 'CVE-2026-0002', 'CVE-2026-0003']);
    });

    it('sends one digest for the team when 20 resources have the same new problem', function () {
        foreach (range(1, 20) as $index) {
            notifyScan(notifyApplication("app-{$index}"), [notifyFinding('CVE-2026-0100')]);
        }

        notifyRunDigest();

        Notification::assertSentToTimes($this->team, VulnerabilityDigest::class, 1);
        expect(notifySentDigest()->digest['resources'])->toHaveCount(20);
    });

    it('notifies again when a fixed problem comes back', function () {
        $scan = notifyScan($this->application, [notifyFinding('CVE-2026-0009', ['fixState' => 'not-fixed', 'fixedIn' => null])]);
        notifyState($this->application, [], digestIds: ['CVE-2026-0001']);

        notifyRunDigest();
        Notification::assertNothingSent();
        expect(notifyStateOf($this->application)->digest_ids)->toBe([]);

        notifyRefresh($scan, ['findings' => [notifyFinding('CVE-2026-0001')]]);
        notifyRunDigest();

        Notification::assertSentToTimes($this->team, VulnerabilityDigest::class, 1);
        expect(notifySentDigest()->digest['resources'][0]['status'])->toBe('update_available');
    });

    it('keeps notified IDs while a deployment of the application runs', function () {
        notifyScan($this->application, []);
        notifyState($this->application, ['CVE-2026-0001'], digestIds: ['CVE-2026-0002']);
        notifyDeployment($this->application)->update(['status' => ApplicationDeploymentStatus::IN_PROGRESS->value]);

        notifyRunDigest();

        expect(notifyStateOf($this->application)->notified_ids)->toBe(['CVE-2026-0001'])
            ->and(notifyStateOf($this->application)->digest_ids)->toBe(['CVE-2026-0002']);
    });

    it('matches reported IDs by alias and skips ignored findings', function () {
        ImageScanIgnore::create(['team_id' => $this->team->id, 'vulnerability_id' => 'CVE-2026-0002', 'reason' => 'Not used.']);
        notifyScan($this->application, [
            notifyFinding('CVE-2026-0001', ['aliases' => ['GHSA-aaaa-bbbb-cccc']]),
            notifyFinding('CVE-2026-0002'),
        ]);
        notifyState($this->application, [], digestIds: ['GHSA-aaaa-bbbb-cccc']);

        notifyRunDigest();

        Notification::assertNothingSent();
    });

    it('sends no digest when nothing changed', function () {
        notifyScan($this->application, [notifyFinding('CVE-2026-0001')]);
        notifyState($this->application, [], digestIds: ['CVE-2026-0001']);

        notifyRunDigest();

        Notification::assertNothingSent();
    });

    it('never notifies for a preview scan and leaves the state alone', function () {
        notifyScan($this->application, [notifyFinding('CVE-2026-0001')]);
        notifyState($this->application, [], digestIds: ['CVE-2026-0001']);
        $preview = notifyDeployment($this->application, 7);
        $scan = notifyScan($this->application, [notifyExploited('CVE-2026-0009')], ['deployment_queue_id' => $preview->id]);

        ScanNotifications::afterScan($scan, $preview->id);
        notifyRunDigest();

        Notification::assertNothingSent();
        expect(notifyStateOf($this->application)->notified_ids)->toBe([])
            ->and(notifyStateOf($this->application)->digest_ids)->toBe(['CVE-2026-0001']);
    });

    it('does not report a released fix again after a redeploy to a new image', function () {
        notifyScan($this->application, [notifyFinding('CVE-2026-0001')], ['candidates' => notifyCandidates(), 'scanned_at' => now()->subDay()]);
        notifyState($this->application, [], digestIds: ['CVE-2026-0001']);
        notifyRunDigest();
        Notification::assertSentToTimes($this->team, VulnerabilityDigest::class, 1);

        $deployment = notifyDeployment($this->application);
        $scan = notifyScan($this->application, [notifyFinding('CVE-2026-0001')], ['deployment_queue_id' => $deployment->id, 'candidates' => notifyCandidates()]);
        ScanNotifications::afterScan($scan, $deployment->id);
        notifyRunDigest();

        Notification::assertSentToTimes($this->team, VulnerabilityDigest::class, 1);
    });

    it('keeps the IDs of an alert that comes while the digest runs', function () {
        $scan = notifyScan($this->application, [notifyFinding('CVE-2026-0001'), notifyFinding('CVE-2026-0002')]);
        notifyState($this->application, [], digestIds: ['CVE-2026-0001']);
        $plan = ScanNotifications::digestPlan($this->application);

        $scan = notifyRefresh($scan, ['findings' => [...$scan->findings, notifyExploited('CVE-2026-0003')]]);
        ScanNotifications::afterScan($scan);
        ScanNotifications::applyDigest($plan['mark']);

        Notification::assertSentToTimes($this->team, ExploitedProblemFound::class, 1);
        expect(notifyStateOf($this->application)->notified_ids)->toBe(['CVE-2026-0003'])
            ->and(notifyStateOf($this->application)->digest_ids)->toEqualCanonicalizing(['CVE-2026-0001', 'CVE-2026-0002', 'CVE-2026-0003']);
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
        expect(notifyStateOf($this->application)->digest_ids)->toEqualCanonicalizing(['CVE-2026-0001', 'CVE-2026-0002']);
    });

    it('records only the checked scan when a version check continues an open baseline', function () {
        $first = notifyDeployment($this->application);
        $scan = notifyScan($this->application, [notifyFinding('CVE-2026-0001')], ['deployment_queue_id' => $first->id, 'scanned_at' => now()->subHour()], state: false);
        ScanNotifications::afterScan($scan, $first->id);
        $second = notifyDeployment($this->application);
        // The redeploy wrote its scan; its own notification runs after the version check below.
        $redeploy = notifyScan($this->application, [notifyFinding('CVE-2026-0002')], ['deployment_queue_id' => $second->id, 'image_id' => 'sha256:'.str_repeat('e', 64)], state: false);

        $scan = notifyRefresh($scan, ['candidates' => notifyCandidates()]);
        ScanNotifications::afterScan($scan, candidateCheck: true);
        expect(notifyStateOf($this->application)->digest_ids)->toBe(['CVE-2026-0001']);

        ScanNotifications::afterScan($redeploy, $second->id);
        notifyRunDigest();

        Notification::assertSentToTimes($this->team, VulnerabilityDigest::class, 1);
        // CVE-2026-0001 left with the old image.
        expect(notifyStateOf($this->application)->digest_ids)->toBe(['CVE-2026-0002']);
    });

    it('keeps the notified candidates while a version check has not run', function () {
        $key = 'nginx:1.28@sha256:'.str_repeat('b', 64);
        notifyScan($this->application, [notifyFinding('CVE-2026-0001')], ['candidates' => null, 'created_at' => now()->subDays(1)->subHour()]);
        notifyState($this->application, [], [$key], digestIds: ['CVE-2026-0001']);

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

        expect(notifyStateOf($this->application)->digest_ids)->toBe([]);
    });

    it('deletes the state with the resource', function () {
        notifyState($this->application, ['CVE-2026-0001']);

        $this->application->delete();

        expect(ImageScanNotificationState::query()->count())->toBe(0);
    });
});

describe('immediate alert', function () {
    it('alerts once for new problems that attackers are using with the action that fixes them', function () {
        $scan = notifyScan($this->application, [notifyExploited('CVE-2026-0001'), notifyExploited('CVE-2026-0002', ['severity' => 'low'])]);

        ScanNotifications::afterScan($scan);
        ScanNotifications::afterScan($scan->fresh());
        notifyRunDigest();

        Notification::assertSentToTimes($this->team, ExploitedProblemFound::class, 1);
        $alert = notifySentAlert();
        // A finding in CISA KEV is relevant at any severity (spec 15.2).
        expect(array_column($alert->why, 'display_id'))->toBe(['CVE-2026-0001', 'CVE-2026-0002'])
            ->and($alert->title())->toBe('Update now')
            ->and($alert->text(withCount: true))->toBe('api: 2 problems that attackers are using. Rebuild the image in your CI, then redeploy.')
            ->and($alert->text(withCount: false))->toBe('api: problems that attackers are using. Rebuild the image in your CI, then redeploy.');
        Notification::assertNotSentTo($this->team, VulnerabilityDigest::class);
    });

    it('alerts for a new problem that attackers are using without a fix', function () {
        $scan = notifyScan($this->application, [notifyExploitedWithoutFix('CVE-2026-0001')]);

        ScanNotifications::afterScan($scan);

        $alert = notifySentAlert();
        expect($alert->title())->toBe('No fix yet')
            ->and($alert->text(withCount: true))->toBe('api: 1 problem that attackers are using, and no fixed version exists yet.')
            ->and($alert->text(withCount: false))->toBe('api: a problem that attackers are using, and no fixed version exists yet.')
            ->and(notifyStateOf($this->application)->notified_ids)->toBe(['CVE-2026-0001'])
            ->and(notifyStateOf($this->application)->digest_ids)->toBe([]);
    });

    it('says attackers are likely to use a problem that only has a high EPSS', function () {
        $scan = notifyScan($this->application, [notifyFinding('CVE-2026-0001', ['epss' => 0.5])]);

        ScanNotifications::afterScan($scan);

        expect(notifySentAlert()->text(withCount: true))->toBe('api: 1 problem that attackers are likely to use. Rebuild the image in your CI, then redeploy.');
    });

    it('does not alert again when the EPSS of a finding drops below and rises above the threshold', function () {
        $scan = notifyScan($this->application, [notifyFinding('CVE-2026-0001', ['epss' => 0.5])]);
        ScanNotifications::afterScan($scan);

        $scan = notifyRefresh($scan, ['findings' => [notifyFinding('CVE-2026-0001', ['epss' => 0.05])]]);
        ScanNotifications::afterScan($scan);
        notifyRunDigest();
        $scan = notifyRefresh($scan, ['findings' => [notifyFinding('CVE-2026-0001', ['epss' => 0.3])]]);
        ScanNotifications::afterScan($scan);
        notifyRunDigest();

        Notification::assertSentToTimes($this->team, ExploitedProblemFound::class, 1);
        Notification::assertNotSentTo($this->team, VulnerabilityDigest::class);
    });

    it('alerts when attackers start using a problem that a digest named before', function () {
        $scan = notifyScan($this->application, [notifyFinding('CVE-2026-0001')]);
        ScanNotifications::afterScan($scan);
        notifyRunDigest();
        Notification::assertSentToTimes($this->team, VulnerabilityDigest::class, 1);

        $scan = notifyRefresh($scan, ['findings' => [notifyExploited('CVE-2026-0001')]]);
        ScanNotifications::afterScan($scan);

        Notification::assertSentToTimes($this->team, ExploitedProblemFound::class, 1);
    });

    it('sends problems without an exploit signal to the digest, not right away', function () {
        $scan = notifyScan($this->application, [notifyFinding('CVE-2026-0001'), notifyFinding('CVE-2026-0002', ['severity' => 'high'])]);

        ScanNotifications::afterScan($scan);
        Notification::assertNothingSent();

        notifyRunDigest();
        Notification::assertSentToTimes($this->team, VulnerabilityDigest::class, 1);
    });

    it('waits for the version check before it alerts a problem without a fix', function () {
        Queue::fake();
        $scan = notifyScan($this->application, [notifyExploitedWithoutFix('CVE-2026-0001')], ['candidates' => null]);

        ScanNotifications::afterScan($scan);
        Notification::assertNothingSent();
        Queue::assertPushed(ImageScanAlertCheckJob::class, fn (ImageScanAlertCheckJob $job) => $job->scanId === $scan->id && $job->delay !== null);

        $scan = notifyRefresh($scan, ['candidates' => notifyCandidates()]);
        ScanNotifications::afterScan($scan, candidateCheck: true);

        Notification::assertSentToTimes($this->team, ExploitedProblemFound::class, 1);
        expect(notifySentAlert()->text(withCount: true))->toBe('api: 1 problem that attackers are using. Update to 1.28.');
    });

    it('does not wait for the version check when the fix does not depend on it', function () {
        Queue::fake();
        $scan = notifyScan($this->application, [notifyExploited('CVE-2026-0001')], ['candidates' => null]);

        ScanNotifications::afterScan($scan);

        expect(notifySentAlert()->text(withCount: true))->toBe('api: 1 problem that attackers are using. Rebuild the image in your CI, then redeploy.');
        Queue::assertNotPushed(ImageScanAlertCheckJob::class);
    });

    it('alerts with neutral wording when the wait for the version check ends without a result', function () {
        Queue::fake();
        $scan = notifyScan($this->application, [notifyExploitedWithoutFix('CVE-2026-0001')], ['candidates' => null]);
        ScanNotifications::afterScan($scan);
        Notification::assertNothingSent();

        $this->travel(61)->minutes();
        (new ImageScanAlertCheckJob($scan->id))->handle();

        $alert = notifySentAlert();
        expect($alert->title())->toBe('Check needed')
            ->and($alert->text(withCount: true))->toBe('api: 1 problem that attackers are using. Coolify could not check for a fixed version yet.')
            ->and($alert->toWebhook()['status'])->toBe('check_needed');
    });

    it('looks again at a scan that is rescanned while its problems wait', function () {
        Queue::fake();
        $scan = notifyScan($this->application, [notifyExploitedWithoutFix('CVE-2026-0001')], ['candidates' => null]);
        ScanNotifications::afterScan($scan);

        $this->travel(30)->minutes();
        $scan = notifyRefresh($scan, ['scanned_at' => now()]);
        ScanNotifications::afterScan($scan);

        Queue::assertPushed(ImageScanAlertCheckJob::class, 2);
        Queue::assertPushed(ImageScanAlertCheckJob::class, fn (ImageScanAlertCheckJob $job) => $job->scannedAt === $scan->scanned_at->toIso8601String());
    });

    it('sends the alert even when the follow-up of a waiting problem cannot be queued', function () {
        $scan = notifyScan($this->application, [notifyExploited('CVE-2026-0001'), notifyExploitedWithoutFix('CVE-2026-0002')], ['candidates' => null]);
        $this->mock(Dispatcher::class)->shouldReceive('dispatch')->andThrow(new RuntimeException('queue down'));

        expect(fn () => ScanNotifications::afterScan($scan))->toThrow(RuntimeException::class);

        expect(array_column(notifySentAlert()->why, 'display_id'))->toBe(['CVE-2026-0001'])
            ->and(notifyStateOf($this->application)->notified_ids)->toBe(['CVE-2026-0001']);
    });

    it('retries the follow-up of a waiting problem when it fails', function () {
        $job = new ImageScanAlertCheckJob(1, '2026-10-27T10:00:00+00:00');

        expect($job->tries)->toBe(3)
            ->and($job->backoff())->toBe([60, 300])
            ->and($job->uniqueId())->toBe('1:2026-10-27T10:00:00+00:00');
    });

    it('alerts at once when the version check job fails', function () {
        Queue::fake();
        $scan = notifyScan($this->application, [notifyExploitedWithoutFix('CVE-2026-0001', ['epss' => 0.5, 'kev' => false])], ['candidates' => null]);
        ScanNotifications::afterScan($scan);
        Notification::assertNothingSent();

        (new RefreshCandidatesJob($scan))->failed(new RuntimeException('registry down'));

        expect(notifySentAlert()->text(withCount: true))->toBe('api: 1 problem that attackers are likely to use. Coolify could not check for a fixed version yet.');
    });

    it('does not say that no fix exists when the version check failed', function () {
        $scan = notifyScan($this->application, [notifyExploitedWithoutFix('CVE-2026-0001')], [
            'candidates' => ['status' => 'failed', 'checked_at' => now()->addSecond()->toIso8601String(), 'error' => 'daily_limit', 'entries' => []],
        ]);

        ScanNotifications::afterScan($scan);

        $alert = notifySentAlert();
        expect($alert->title())->toBe('Check needed')
            ->and($alert->text(withCount: false))->toBe('api: a problem that attackers are using. Coolify could not check for a fixed version yet.');
    });

    it('alerts a new exploited problem while the resource needs a check for another reason', function () {
        $scan = notifyScan($this->application, [notifyFinding('CVE-2026-0001')], ['scanned_at' => now()->subDays(3)]);

        notifyRefresh($scan, ['findings' => [notifyExploited('CVE-2026-0002')], 'scanned_at' => now()->subDays(3)]);
        ScanNotifications::afterScan($scan->fresh());

        Notification::assertSentToTimes($this->team, ExploitedProblemFound::class, 1);
    });

    it('alerts a new exploited finding of a later rescan of the first deployed image', function () {
        $deployment = notifyDeployment($this->application);
        $scan = notifyScan($this->application, [notifyFinding('CVE-2026-0001')], ['is_baseline' => true, 'deployment_queue_id' => $deployment->id], state: false);
        ScanNotifications::afterScan($scan, $deployment->id);

        $this->travel(3)->hours();
        $scan = notifyRefresh($scan, ['findings' => [notifyFinding('CVE-2026-0001'), notifyExploited('CVE-2026-0002')], 'scanned_at' => now()]);
        ScanNotifications::afterScan($scan);

        Notification::assertSentToTimes($this->team, ExploitedProblemFound::class, 1);
        expect(array_column(notifySentAlert()->why, 'display_id'))->toBe(['CVE-2026-0002']);
    });

    it('notifies only the root team for the resources of Coolify', function () {
        $root = Team::find(0) ?? Team::factory()->create(['id' => 0, 'name' => 'Root Team']);
        notifyEnableDiscord($root);
        [, $rootDestination, $rootEnvironment] = notifyTeamSetup($root);
        $coolify = Application::factory()->create([
            'id' => 0, 'name' => 'coolify', 'environment_id' => $rootEnvironment->id,
            'destination_id' => $rootDestination->id, 'destination_type' => $rootDestination->getMorphClass(),
            'build_pack' => 'dockerimage', 'docker_registry_image_name' => 'nginx', 'docker_registry_image_tag' => '1.27',
        ]);
        $scan = notifyScan($coolify, [notifyExploited('CVE-2026-0001'), notifyFinding('CVE-2026-0002', ['severity' => 'high'])], ['server_id' => $rootDestination->server_id]);

        ScanNotifications::afterScan($scan);
        notifyRunDigest();

        Notification::assertSentToTimes($root, ExploitedProblemFound::class, 1);
        Notification::assertNotSentTo($this->team, ExploitedProblemFound::class);
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
        $scan = notifyScan($this->application, [notifyExploited('CVE-2026-0001')]);
        ScanNotifications::afterScan($scan);

        (new TelegramChannel)->send($this->team->fresh(), notifySentAlert());

        Bus::assertDispatched(SendMessageToTelegramJob::class, fn ($job) => $job->threadId === '77');
    });
});

describe('digest', function () {
    it('names a resource that became update_available with its status and action', function () {
        notifyScan($this->application, [notifyFinding('CVE-2026-0001', ['severity' => 'high'])]);

        notifyRunDigest();

        $resource = notifySentDigest()->digest['resources'][0];
        expect($resource['status'])->toBe('update_available')
            ->and($resource['badge'])->toBe('Security update')
            ->and($resource['action'])->toBe('Rebuild the image in your CI, then redeploy.');
    });

    it('reports a newly released fixing version once', function () {
        $key = 'nginx:1.28@sha256:'.str_repeat('b', 64);
        notifyScan($this->application, [notifyFinding('CVE-2026-0001', ['type' => 'npm', 'package' => 'lodash'])], ['candidates' => notifyCandidates()]);
        notifyState($this->application, [], digestIds: ['CVE-2026-0001']);

        notifyRunDigest();
        notifyRunDigest();

        Notification::assertSentToTimes($this->team, VulnerabilityDigest::class, 1);
        expect(notifySentDigest()->digest['resources'][0]['action'])->toBe('Update to 1.28.')
            ->and(notifyStateOf($this->application)->notified_candidates)->toBe([$key]);
    });

    it('does not report the check needed right after a scan while the version check runs', function () {
        notifyScan($this->application, [notifyFinding('CVE-2026-0001')], ['candidates' => null]);

        notifyRunDigest();

        Notification::assertNothingSent();
    });

    it('reports a check needed once when the version check did not run for a day', function () {
        notifyScan($this->application, [notifyFinding('CVE-2026-0001')], ['candidates' => null, 'created_at' => now()->subDays(1)->subHour()]);

        notifyRunDigest();
        notifyRunDigest();

        Notification::assertSentToTimes($this->team, VulnerabilityDigest::class, 1);
        $resource = notifySentDigest()->digest['resources'][0];
        expect($resource['status'])->toBe('check_needed')
            ->and($resource['badge'])->toBe('Check needed')
            ->and($resource['action'])->toBe('Check again: Coolify could not check for newer versions.');
    });

    it('reports a stale result once and the new problems after the next check', function () {
        $scan = notifyScan($this->application, [notifyFinding('CVE-2026-0001')], ['scanned_at' => now()->subDays(3)]);

        notifyRunDigest();
        notifyRunDigest();

        Notification::assertSentToTimes($this->team, VulnerabilityDigest::class, 1);
        expect(notifySentDigest()->digest['resources'][0]['status'])->toBe('check_needed')
            ->and(notifyStateOf($this->application)->check_reported)->toBeTrue()
            ->and(notifyStateOf($this->application)->digest_ids)->toBe([]);

        notifyRefresh($scan, ['scanned_at' => now()]);
        notifyRunDigest();

        Notification::assertSentToTimes($this->team, VulnerabilityDigest::class, 2);
        expect(notifyStateOf($this->application)->check_reported)->toBeFalse()
            ->and(notifyStateOf($this->application)->digest_ids)->toBe(['CVE-2026-0001']);
    });

    it('names a fix of an exploited problem at once while the resource needs a check', function () {
        $key = 'nginx:1.28@sha256:'.str_repeat('b', 64);
        $scan = notifyScan($this->application, [notifyExploitedWithoutFix('CVE-2026-0001')], ['candidates' => notifyCandidates(), 'scanned_at' => now()->subDays(3)]);
        notifyStateOf($this->application)->update(['check_reported' => true]);

        notifyRunDigest();
        // The alert does not name the problem again.
        ScanNotifications::afterScan($scan);

        $resource = notifySentDigest()->digest['resources'][0];
        expect($resource['status'])->toBe('check_needed')
            ->and($resource['action'])->toBe('Update to 1.28.')
            ->and(notifyStateOf($this->application)->digest_ids)->toBe(['CVE-2026-0001'])
            ->and(notifyStateOf($this->application)->notified_ids)->toBe(['CVE-2026-0001'])
            ->and(notifyStateOf($this->application)->notified_candidates)->toBe([$key]);
        Notification::assertNotSentTo($this->team, ExploitedProblemFound::class);
    });

    it('names held problems after 3 digests while the resource still needs a check', function () {
        notifyScan($this->application, [notifyFinding('CVE-2026-0001')], ['scanned_at' => now()->subDays(3)]);

        foreach (range(1, 3) as $run) {
            notifyRunDigest();
        }
        Notification::assertSentToTimes($this->team, VulnerabilityDigest::class, 1);
        expect(notifyStateOf($this->application)->held_digests)->toBe(3)
            ->and(notifyStateOf($this->application)->digest_ids)->toBe([]);

        notifyRunDigest();
        notifyRunDigest();

        Notification::assertSentToTimes($this->team, VulnerabilityDigest::class, 2);
        $sent = Notification::sent($this->team, VulnerabilityDigest::class)->last();
        expect($sent->digest['resources'][0]['status'])->toBe('check_needed')
            ->and($sent->digest['resources'][0]['action'])->toBe('Rebuild the image in your CI, then redeploy.')
            ->and(notifyStateOf($this->application)->held_digests)->toBe(0)
            ->and(notifyStateOf($this->application)->digest_ids)->toBe(['CVE-2026-0001']);
    });

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
    it('puts no vulnerability IDs and no counts in chat and webhook payloads', function () {
        notifyScan($this->application, [
            notifyExploited('CVE-2026-0001'),
            notifyExploited('GHSA-aaaa-bbbb-cccc'),
        ]);
        ImageScanIgnore::create(['team_id' => $this->team->id, 'vulnerability_id' => 'CVE-2026-0300', 'reason' => 'Later.', 'expires_at' => now()->addDays(3)]);
        ImageScanIgnore::create(['team_id' => $this->team->id, 'vulnerability_id' => 'CVE-2026-0301', 'reason' => 'Later.', 'expires_at' => now()->addDays(4)]);
        $scan = ImageScan::query()->sole();

        ScanNotifications::afterScan($scan);
        notifyRefresh($scan, ['findings' => [...$scan->findings, notifyFinding('CVE-2026-0002'), notifyFinding('CVE-2026-0003')]]);
        notifyRunDigest();

        foreach ([notifySentDigest(), notifySentAlert()] as $notification) {
            foreach (notifyChatPayloads($notification) as $channel => $payload) {
                expect($payload)->not->toContain('CVE-', "{$channel} payload")
                    ->and($payload)->not->toContain('GHSA-', "{$channel} payload")
                    ->and(preg_match(notifyCountPattern(), $payload))->toBe(0, "{$channel} payload");
            }
        }
        expect(notifySentAlert()->toWebhook())->toMatchArray(['status' => 'update_now', 'action' => 'Rebuild the image in your CI, then redeploy.'])
            ->and(notifySentDigest()->toWebhook()['resources'][0])->toBe([
                'name' => 'api', 'uuid' => $this->application->uuid, 'url' => notifySentDigest()->digest['resources'][0]['url'],
                'status' => 'update_now', 'action' => 'Rebuild the image in your CI, then redeploy.',
            ]);
    });

    it('escapes the titles of the why-list in the email and links the IDs', function () {
        $title = '*bold* <b>x</b> [link](https://evil.example)';
        $scan = notifyScan($this->application, [notifyExploited('CVE-2026-0001', ['title' => $title])]);
        ScanNotifications::afterScan($scan);
        notifyScan(notifyApplication('web'), [notifyFinding('CVE-2026-0002', ['title' => $title])]);
        notifyRunDigest();

        foreach ([notifySentAlert(), notifySentDigest()] as $notification) {
            $mail = (string) $notification->toMail($this->team)->render();
            expect($mail)->not->toContain('<em>bold</em>')
                ->and($mail)->not->toContain('<b>x</b>')
                ->and($mail)->not->toContain('href="https://evil.example"')
                ->and($mail)->toContain('href="https://nvd.nist.gov/vuln/detail/CVE-2026-000');
        }
    });

    it('lists at most 10 problems in the digest email', function () {
        foreach (range(1, 5) as $index) {
            notifyScan(notifyApplication("app-{$index}"), array_map(fn (int $n) => notifyFinding(sprintf('CVE-2026-%02d%02d', $index, $n)), range(1, 3)));
        }

        notifyRunDigest();

        $html = (string) notifySentDigest()->toMail($this->team)->render();
        preg_match_all('/CVE-2026-\d{4}/', $html, $matches);
        expect(count(array_unique($matches[0])))->toBe(10)
            ->and($html)->toContain('https://coolify.example.com/project/');
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

    it('fits the alert in the channel limits and keeps only values', function () {
        $this->application->update(['name' => str_repeat('&', 100)]);
        $scan = notifyScan($this->application, [notifyExploited('CVE-2026-0001'), notifyExploited('CVE-2026-0002')], [
            'image_ref' => 'ghcr.io/'.str_repeat('a', 300).':1.0',
        ]);
        ScanNotifications::afterScan($scan);
        $notification = notifySentAlert();
        $scan->delete();

        $notification = unserialize(serialize($notification));
        $mail = (string) $notification->toMail($this->team)->render();

        expect(mb_strlen($notification->toPushover()->toPayload('token', 'user')['message']))->toBeLessThanOrEqual(1024)
            ->and(mb_strlen($notification->toDiscord()->toPayload()['embeds'][0]['description']))->toBeLessThanOrEqual(4096)
            ->and($mail)->toContain('CVE-2026-0002')
            ->and($mail)->toContain('2 problems that attackers are using');
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
