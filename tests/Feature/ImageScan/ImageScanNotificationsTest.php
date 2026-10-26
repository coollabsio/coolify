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
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
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
 * A completed scan that is not the first of its resource, with nothing notified yet.
 *
 * @param  list<array<string, mixed>>  $findings
 * @param  array<string, mixed>  $attributes
 */
function notifyScan(Model $resource, array $findings, array $attributes = []): ImageScan
{
    return ImageScan::factory()->create([
        'server_id' => test()->server->id,
        'resource_type' => $resource->getMorphClass(),
        'resource_id' => $resource->getKey(),
        'image_ref' => 'nginx:1.27',
        'builds_image' => true,
        'findings' => $findings,
        'summary' => [],
        'is_baseline' => false,
        'notified_ids' => [],
        ...$attributes,
    ]);
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

describe('notified IDs', function () {
    it('sends nothing for a baseline scan and remembers its fixable IDs', function () {
        $scan = notifyScan($this->application, [notifyFinding('CVE-2026-0001'), notifyFinding('CVE-2026-0002', ['fixState' => 'not-fixed', 'fixedIn' => null])], [
            'is_baseline' => true,
            'notified_ids' => null,
        ]);

        ScanNotifications::afterScan($scan);
        notifyRunDigest();

        Notification::assertNothingSent();
        expect($scan->fresh()->notified_ids)->toBe(['CVE-2026-0001']);
    });

    it('treats a scan without notified IDs as a baseline in the digest', function () {
        $scan = notifyScan($this->application, [notifyFinding('CVE-2026-0001')], ['notified_ids' => null]);

        notifyRunDigest();

        Notification::assertNothingSent();
        expect($scan->fresh()->notified_ids)->toBe(['CVE-2026-0001']);
    });

    it('sends one digest for new fixable findings on the next run, not two', function () {
        $scan = notifyScan($this->application, [notifyFinding('CVE-2026-0001')], ['is_baseline' => true, 'notified_ids' => null]);
        ScanNotifications::afterScan($scan);

        $scan->update(['is_baseline' => false, 'findings' => [
            notifyFinding('CVE-2026-0001'), notifyFinding('CVE-2026-0002'), notifyFinding('CVE-2026-0003'),
            notifyFinding('CVE-2026-0004', ['severity' => 'low']),
        ]]);
        ScanNotifications::afterScan($scan->fresh());
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
            ->and($scan->fresh()->notified_ids)->toBe(['CVE-2026-0001', 'CVE-2026-0002', 'CVE-2026-0003']);
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
        $scan = notifyScan($this->application, [notifyFinding('CVE-2026-0001')], ['notified_ids' => ['CVE-2026-0001']]);

        $scan->update(['findings' => [notifyFinding('CVE-2026-0009', ['fixState' => 'not-fixed', 'fixedIn' => null])]]);
        ScanNotifications::afterScan($scan->fresh());
        expect($scan->fresh()->notified_ids)->toBe([]);
        notifyRunDigest();
        Notification::assertNothingSent();

        $scan->update(['findings' => [notifyFinding('CVE-2026-0001')]]);
        ScanNotifications::afterScan($scan->fresh());
        notifyRunDigest();

        Notification::assertSentToTimes($this->team, VulnerabilityDigest::class, 1);
        expect(notifySentDigest()->digest['resources'][0]['ids'])->toBe(['CVE-2026-0001']);
    });

    it('keeps the notified IDs of the previous image of the resource for a new image', function () {
        notifyScan($this->application, [notifyFinding('CVE-2026-0001')], ['notified_ids' => ['CVE-2026-0001'], 'scanned_at' => now()->subDay()]);
        $scan = notifyScan($this->application, [notifyFinding('CVE-2026-0001'), notifyFinding('CVE-2026-0002')], ['notified_ids' => null]);

        ScanNotifications::afterScan($scan);
        notifyRunDigest();

        expect(notifySentDigest()->digest['resources'][0]['ids'])->toBe(['CVE-2026-0002']);
    });

    it('matches notified IDs by alias and skips ignored findings', function () {
        notifyScan($this->application, [
            notifyFinding('CVE-2026-0001', ['aliases' => ['GHSA-aaaa-bbbb-cccc']]),
            notifyFinding('CVE-2026-0002'),
        ], ['notified_ids' => ['GHSA-aaaa-bbbb-cccc']]);
        ImageScanIgnore::create(['team_id' => $this->team->id, 'vulnerability_id' => 'CVE-2026-0002', 'reason' => 'Not used.']);

        notifyRunDigest();

        Notification::assertNothingSent();
    });

    it('sends no digest when nothing changed', function () {
        notifyScan($this->application, [notifyFinding('CVE-2026-0001')], ['notified_ids' => ['CVE-2026-0001']]);

        notifyRunDigest();

        Notification::assertNothingSent();
    });
});

describe('known exploited', function () {
    it('alerts right away for a new known exploited finding and leaves it out of the digest', function () {
        $scan = notifyScan($this->application, [notifyFinding('CVE-2026-0001', ['kev' => true]), notifyFinding('CVE-2026-0002', ['kev' => true, 'severity' => 'low'])]);

        ScanNotifications::afterScan($scan);
        ScanNotifications::afterScan($scan->fresh());
        notifyRunDigest();

        Notification::assertSentToTimes($this->team, KnownExploitedFound::class, 1);
        Notification::assertSentTo($this->team, KnownExploitedFound::class, fn (KnownExploitedFound $notification) => $notification->count === 1
            && $notification->resourceName === 'api'
            && $notification->worst === 'critical');
        Notification::assertNotSentTo($this->team, VulnerabilityDigest::class);
    });

    it('never alerts for a baseline scan', function () {
        $scan = notifyScan($this->application, [notifyFinding('CVE-2026-0001', ['kev' => true])], ['is_baseline' => true, 'notified_ids' => null]);

        ScanNotifications::afterScan($scan);

        Notification::assertNothingSent();
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

    it('reports a newly released fix once', function () {
        notifyScan($this->application, [], [
            'notified_ids' => [],
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
        notifyScan($this->application, [notifyFinding('CVE-2026-0001')], [
            'candidates' => ['status' => 'ok', 'checked_at' => now()->toIso8601String(), 'notified' => ['nginx:1.28@sha256:'.str_repeat('b', 64)], 'entries' => [[
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
    it('titles a blocked deploy "Deploy blocked" with counts only in every channel', function () {
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
                'headline' => 'Deploy blocked: 2 critical vulnerabilities with a fix.',
                'top' => [['id' => 'CVE-2026-1234', 'severity' => 'critical', 'package' => 'openssl', 'version' => '3.0.13', 'fixed_in' => '3.0.15', 'kev' => false]],
            ],
        ]);

        $notification = new DeploymentFailed($this->application, $deployment->deployment_uuid, null, $deployment);

        expect($notification->toDiscord()->title)->toStartWith('Deploy blocked: 2 critical vulnerabilities with a fix')
            ->and($notification->toSlack()->title)->toStartWith('Deploy blocked')
            ->and($notification->toPushover()->title)->toStartWith('Deploy blocked')
            ->and($notification->toTelegram()['message'])->toContain('Deploy blocked: 2 critical vulnerabilities with a fix')
            ->and($notification->toWebhook()['message'])->toStartWith('Deploy blocked')
            ->and($notification->toMail($this->team)->subject)->toContain('Deploy blocked');
        foreach (notifyChatPayloads($notification) as $payload) {
            expect($payload)->not->toContain('CVE-');
        }
        expect((string) $notification->toMail($this->team)->render())->not->toContain('CVE-');
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
