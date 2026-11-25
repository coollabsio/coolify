<?php

use App\Enums\ConfigRevisionStatus;
use App\Jobs\PlanEnvironmentConfigJob;
use App\Models\EnvironmentConfigLink;
use App\Models\EnvironmentConfigRevision;
use App\Notifications\ProjectConfig\ConfigApplyFailed;
use App\Notifications\ProjectConfig\ConfigApprovalNeeded;
use App\Services\ProjectConfig\ConfigRevisions;
use App\Services\ProjectConfig\RepositoryFile;
use App\Services\ProjectConfig\RepositoryFileReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    Notification::fake();
    $this->fx = projectConfigFixture();
    $this->fx['team']->emailNotificationSettings()->update(['use_instance_email_settings' => true]);
    $this->link = EnvironmentConfigLink::factory()->create([
        'environment_id' => $this->fx['environment']->id, 'mode' => 'git', 'repository' => 'acme/api',
        'branch' => 'main', 'read_server_id' => $this->fx['server']->id,
    ])->fresh();
});

function notificationsReader(string $contents): void
{
    app()->instance(RepositoryFileReader::class, new class($contents) extends RepositoryFileReader
    {
        public function __construct(private string $contents) {}

        public function read(EnvironmentConfigLink $link, ?string $commit = null): RepositoryFile
        {
            return new RepositoryFile($this->contents, str_repeat('a', 40), false);
        }
    });
}

it('notifies the team of the link when a push fails', function () {
    notificationsReader('{"version": 1, "resources": {}, "environments": {"production": {"branch": "main"}}, "nope": true}');

    PlanEnvironmentConfigJob::dispatchSync($this->link, 'push');

    Notification::assertSentTo($this->fx['team'], ConfigApplyFailed::class, function (ConfigApplyFailed $notification) {
        return $notification->revision->status === ConfigRevisionStatus::Failed
            && str_contains($notification->toMail()->render(), 'Unknown key');
    });
});

it('notifies when a plan waits for approval, without values', function () {
    $secretFile = json_encode(['version' => 1, 'resources' => ['api' => [
        'type' => 'application', 'server' => 'prod-1', 'build' => ['pack' => 'dockerfile'], 'ports' => ['exposes' => '3000'],
        'source' => ['repository' => 'https://github.com/acme/api', 'branch' => 'main'],
        'env' => ['TOKEN' => ['value' => 'very-secret-value']],
    ]], 'environments' => ['production' => ['branch' => 'main']]]);
    $this->link->update(['auto_apply' => false]);

    app(ConfigRevisions::class)->record($this->link->fresh(), $secretFile, 'push', str_repeat('b', 40));

    Notification::assertSentTo($this->fx['team'], ConfigApprovalNeeded::class, function (ConfigApprovalNeeded $notification) {
        $all = json_encode([$notification->toWebhook(), $notification->toTelegram(), $notification->toSlack()->description, $notification->toDiscord()->description]).$notification->toMail()->render();

        return ! str_contains($all, 'very-secret-value') && str_contains($all, 'production');
    });
});

it('does not notify for a plan made through the API', function () {
    $this->link->update(['auto_apply' => false]);

    app(ConfigRevisions::class)->record($this->link->fresh(), json_encode(['version' => 1, 'resources' => [], 'environments' => ['production' => ['branch' => 'main']]]), 'api');

    Notification::assertNothingSent();
    expect(EnvironmentConfigRevision::first()->status)->toBe(ConfigRevisionStatus::WaitingApproval);
});
