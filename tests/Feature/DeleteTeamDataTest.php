<?php

use App\Actions\Team\DeleteTeamData;
use App\Jobs\ReleaseManagedDnsRecordsJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ApplicationPreview;
use App\Models\CloudProviderToken;
use App\Models\DnsProviderZone;
use App\Models\DockerCleanupExecution;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\GithubApp;
use App\Models\GitlabApp;
use App\Models\InstanceSettings;
use App\Models\IntegrationToken;
use App\Models\LocalFileVolume;
use App\Models\LocalPersistentVolume;
use App\Models\ManagedDnsRecord;
use App\Models\ManagedDnsRecordReference;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\S3Storage;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledDatabaseBackupExecution;
use App\Models\ScheduledTask;
use App\Models\ScheduledTaskExecution;
use App\Models\ScheduledVolumeBackup;
use App\Models\ScheduledVolumeBackupExecution;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use App\Models\SharedEnvironmentVariable;
use App\Models\SslCertificate;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Subscription;
use App\Models\Tag;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    Storage::fake('images');
    Storage::fake('ssh-keys');
    Process::fake();
});

/**
 * Creates a team with at least one row in every table that holds team data.
 *
 * @return array{team: Team, project: Project, server: Server}
 */
function populatedTeam(User $member): array
{
    $team = Team::factory()->create();
    $team->members()->attach($member, ['role' => 'owner']);
    $member->update(['current_team_id' => $team->id]);

    Subscription::factory()->create(['team_id' => $team->id]);
    TeamInvitation::create(['team_id' => $team->id, 'uuid' => (string) Str::uuid(), 'email' => fake()->email(), 'role' => 'member', 'link' => 'https://example.com/invite']);
    $member->tokens()->create(['name' => 'cli', 'token' => Str::random(64), 'abilities' => ['read'], 'team_id' => $team->id]);

    $privateKey = PrivateKey::factory()->create(['team_id' => $team->id]);
    $s3 = S3Storage::forceCreate(['uuid' => (string) Str::uuid(), 'name' => 'usable', 'region' => 'us', 'key' => 'k', 'secret' => 's', 'bucket' => 'b', 'endpoint' => 'https://s3.example.com', 'team_id' => $team->id, 'is_usable' => true]);
    S3Storage::forceCreate(['uuid' => (string) Str::uuid(), 'name' => 'unusable', 'region' => 'us', 'key' => 'k', 'secret' => 's', 'bucket' => 'b', 'endpoint' => 'https://s3.example.com', 'team_id' => $team->id, 'is_usable' => false]);
    GithubApp::forceCreate(['uuid' => (string) Str::uuid(), 'name' => 'gh', 'api_url' => 'https://api.github.com', 'html_url' => 'https://github.com', 'is_system_wide' => false, 'is_public' => false, 'team_id' => $team->id, 'private_key_id' => $privateKey->id]);
    GitlabApp::forceCreate(['uuid' => (string) Str::uuid(), 'name' => 'gl', 'api_url' => 'https://gitlab.com/api/v4', 'html_url' => 'https://gitlab.com', 'is_system_wide' => false, 'is_public' => false, 'team_id' => $team->id]);
    CloudProviderToken::factory()->create(['team_id' => $team->id]);
    $integrationToken = IntegrationToken::factory()->create(['team_id' => $team->id]);
    $dnsRecord = ManagedDnsRecord::factory()->create(['dns_provider_zone_id' => DnsProviderZone::factory()->create(['integration_token_id' => $integrationToken->id])->id]);
    $tag = Tag::create(['name' => 'tag-'.Str::random(6), 'team_id' => $team->id]);

    $server = Server::factory()->create(['team_id' => $team->id, 'private_key_id' => $privateKey->id]);
    $trashedServer = Server::factory()->create(['team_id' => $team->id, 'private_key_id' => $privateKey->id]);
    $trashedServer->delete();
    $destination = StandaloneDocker::query()->where('server_id', $server->id)->firstOrFail();
    DockerCleanupExecution::create(['server_id' => $server->id]);
    SslCertificate::forceCreate(['server_id' => $server->id, 'ssl_certificate' => 'cert', 'ssl_private_key' => 'key', 'common_name' => 'ca', 'valid_until' => now()->addYear(), 'is_ca_certificate' => true]);

    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    SharedEnvironmentVariable::forceCreate(['key' => 'TEAM', 'value' => 'x', 'type' => 'team', 'team_id' => $team->id]);
    SharedEnvironmentVariable::forceCreate(['key' => 'PROJECT', 'value' => 'x', 'type' => 'project', 'team_id' => $team->id, 'project_id' => $project->id]);
    SharedEnvironmentVariable::forceCreate(['key' => 'ENV', 'value' => 'x', 'type' => 'environment', 'team_id' => $team->id, 'project_id' => $project->id, 'environment_id' => $environment->id]);

    $onDestination = ['environment_id' => $environment->id, 'destination_id' => $destination->id, 'destination_type' => StandaloneDocker::class];

    $application = Application::factory()->create($onDestination);
    $application->tags()->attach($tag);
    ManagedDnsRecordReference::create(['managed_dns_record_id' => $dnsRecord->id, 'resource_type' => Application::class, 'resource_id' => $application->id]);
    EnvironmentVariable::create(['key' => 'APP_SECRET', 'value' => 'x', 'resourceable_type' => Application::class, 'resourceable_id' => $application->id]);
    $appVolume = LocalPersistentVolume::create(['name' => 'app-data-'.Str::random(6), 'mount_path' => '/data', 'resource_type' => Application::class, 'resource_id' => $application->id]);
    LocalFileVolume::create(['fs_path' => '/tmp/app.env', 'mount_path' => '/app/.env', 'content' => 'SECRET=1', 'resource_type' => Application::class, 'resource_id' => $application->id]);
    $volumeBackup = ScheduledVolumeBackup::forceCreate(['uuid' => (string) Str::uuid(), 'backupable_type' => LocalPersistentVolume::class, 'backupable_id' => $appVolume->id, 'team_id' => $team->id, 'frequency' => '0 0 * * *']);
    ScheduledVolumeBackupExecution::forceCreate(['uuid' => (string) Str::uuid(), 'scheduled_volume_backup_id' => $volumeBackup->id, 'status' => 'success']);
    $preview = ApplicationPreview::forceCreate(['uuid' => (string) Str::uuid(), 'application_id' => $application->id, 'pull_request_id' => 1, 'pull_request_html_url' => 'https://github.com/a/b/pull/1']);
    $preview->delete();
    ApplicationDeploymentQueue::forceCreate(['application_id' => (string) $application->id, 'deployment_uuid' => (string) Str::uuid(), 'server_id' => $server->id, 'status' => 'finished']);
    $appTask = ScheduledTask::factory()->create(['team_id' => $team->id, 'application_id' => $application->id]);
    ScheduledTaskExecution::create(['scheduled_task_id' => $appTask->id, 'status' => 'success']);

    Application::factory()->create($onDestination)->delete();
    Application::factory()->create(['environment_id' => $environment->id, 'destination_id' => 999999, 'destination_type' => StandaloneDocker::class]);

    $database = StandalonePostgresql::create([...$onDestination, 'name' => 'db', 'postgres_user' => 'postgres', 'postgres_password' => 'secret', 'postgres_db' => 'db', 'image' => 'postgres:17']);
    LocalFileVolume::create(['fs_path' => '/tmp/init.sql', 'mount_path' => '/docker-entrypoint-initdb.d/init.sql', 'content' => 'select 1', 'resource_type' => StandalonePostgresql::class, 'resource_id' => $database->id]);
    $databaseBackup = ScheduledDatabaseBackup::create(['team_id' => $team->id, 'frequency' => '0 0 * * *', 'database_type' => StandalonePostgresql::class, 'database_id' => $database->id, 's3_storage_id' => $s3->id]);
    ScheduledDatabaseBackupExecution::create(['scheduled_database_backup_id' => $databaseBackup->id, 'status' => 'success']);

    $service = Service::factory()->create($onDestination);
    $service->tags()->attach($tag);
    EnvironmentVariable::create(['key' => 'SERVICE_PASSWORD', 'value' => 'x', 'resourceable_type' => Service::class, 'resourceable_id' => $service->id]);
    $serviceApplication = ServiceApplication::create(['name' => 'web', 'service_id' => $service->id, 'image' => 'nginx']);
    LocalPersistentVolume::create(['name' => 'svc-data-'.Str::random(6), 'mount_path' => '/data', 'resource_type' => ServiceApplication::class, 'resource_id' => $serviceApplication->id]);
    LocalFileVolume::create(['fs_path' => '/tmp/nginx.conf', 'mount_path' => '/etc/nginx.conf', 'content' => 'server {}', 'resource_type' => ServiceApplication::class, 'resource_id' => $serviceApplication->id]);
    ServiceApplication::create(['name' => 'old', 'service_id' => $service->id, 'image' => 'nginx'])->delete();
    $serviceDatabase = ServiceDatabase::create(['name' => 'db', 'service_id' => $service->id, 'image' => 'postgres:17']);
    $serviceBackup = ScheduledDatabaseBackup::create(['team_id' => $team->id, 'frequency' => '0 0 * * *', 'database_type' => ServiceDatabase::class, 'database_id' => $serviceDatabase->id]);
    ScheduledDatabaseBackupExecution::create(['scheduled_database_backup_id' => $serviceBackup->id, 'status' => 'success']);
    $serviceTask = ScheduledTask::factory()->create(['team_id' => $team->id, 'service_id' => $service->id]);
    ScheduledTaskExecution::create(['scheduled_task_id' => $serviceTask->id, 'status' => 'success']);

    return ['team' => $team, 'project' => $project, 'server' => $server];
}

/**
 * @return array<string, int>
 */
function tableRowCounts(): array
{
    return collect(Schema::getTableListing(schemaQualified: false))
        ->reject(fn (string $table) => in_array($table, ['migrations', 'audit_events', 'activity_log'], true))
        ->mapWithKeys(fn (string $table) => [$table => DB::table($table)->count()])
        ->sortKeys()
        ->all();
}

test('it deletes every record of the team and leaves other teams untouched', function () {
    $member = User::factory()->create();
    $keptTeam = populatedTeam(User::factory()->create())['team'];
    $baseline = tableRowCounts();

    $doomed = populatedTeam($member);
    $doomed['project']->forceFill(['icon_path' => 'project-icons/icon.png', 'icon_storage_type' => 'local'])->save();
    Storage::disk('images')->put('project-icons/icon.png', 'png');

    app(DeleteTeamData::class)->handle($doomed['team']);

    expect(tableRowCounts())->toBe($baseline)
        ->and(Storage::disk('images')->exists('project-icons/icon.png'))->toBeFalse()
        ->and($member->fresh()->current_team_id)->toBeNull();
    $this->assertModelExists($keptTeam);
    $this->assertModelExists($member);
});

/**
 * Stores personal data in every notification settings row of the team.
 */
function customizeNotificationSettings(Team $team): void
{
    $team->emailNotificationSettings()->update(['smtp_enabled' => true, 'smtp_from_address' => 'owner@example.com', 'smtp_recipients' => 'owner@example.com']);
    $team->discordNotificationSettings()->update(['discord_enabled' => true, 'discord_webhook_url' => encrypt('https://discord.com/api/webhooks/1/secret')]);
    $team->slackNotificationSettings()->update(['slack_enabled' => true, 'slack_webhook_url' => encrypt('https://hooks.slack.com/services/secret')]);
    $team->telegramNotificationSettings()->update(['telegram_enabled' => true, 'telegram_token' => encrypt('123:secret'), 'telegram_chat_id' => encrypt('42')]);
    $team->pushoverNotificationSettings()->update(['pushover_enabled' => true, 'pushover_user_key' => encrypt('user-key'), 'pushover_api_token' => encrypt('api-token')]);
    $team->webhookNotificationSettings()->update(['webhook_enabled' => true, 'webhook_url' => encrypt('https://example.com/webhook')]);
}

/**
 * The decrypted notification settings of a team, without keys and timestamps.
 *
 * @return array<string, array<string, mixed>>
 */
function notificationSettingsValues(Team $team): array
{
    $team = $team->fresh();

    return collect(['emailNotificationSettings', 'discordNotificationSettings', 'slackNotificationSettings', 'telegramNotificationSettings', 'pushoverNotificationSettings', 'webhookNotificationSettings'])
        ->mapWithKeys(fn (string $relation) => [$relation => collect($team->{$relation}->getAttributes())
            ->except(['id', 'team_id', 'created_at', 'updated_at'])
            ->map(fn ($value, string $column) => $team->{$relation}->{$column})
            ->all()])
        ->all();
}

test('purging keeps only the team, its memberships, and default notification settings', function () {
    $member = User::factory()->create();
    $otherTeam = populatedTeam(User::factory()->create())['team'];
    $baseline = tableRowCounts();

    $purged = populatedTeam($member);
    $team = $purged['team'];
    $team->forceFill(['personal_team' => true])->save();
    customizeNotificationSettings($team);
    DB::table('sessions')->insert(['id' => Str::random(40), 'user_id' => $member->id, 'payload' => '', 'last_activity' => now()->timestamp]);

    app(DeleteTeamData::class)->purge($team);

    $expected = $baseline;
    foreach (['teams', 'team_user', 'sessions', 'email_notification_settings', 'discord_notification_settings', 'slack_notification_settings', 'telegram_notification_settings', 'pushover_notification_settings', 'webhook_notification_settings'] as $table) {
        $expected[$table]++;
    }
    expect(tableRowCounts())->toBe($expected)
        ->and(notificationSettingsValues($team))->toBe(notificationSettingsValues($otherTeam))
        ->and($team->fresh()->personal_team)->toBeTrue()
        ->and($member->fresh()->current_team_id)->toBe($team->id)
        ->and($member->teams()->whereKey($team->id)->exists())->toBeTrue();
});

test('it runs nothing on the team servers and leaves the DNS records at the provider', function () {
    Bus::fake([ReleaseManagedDnsRecordsJob::class]);
    $doomed = populatedTeam(User::factory()->create());

    app(DeleteTeamData::class)->handle($doomed['team']);

    Process::assertNothingRan();
    Bus::assertNotDispatched(ReleaseManagedDnsRecordsJob::class);
});

test('it never purges or deletes the root team', function (string $method) {
    $rootTeam = Team::find(0) ?? Team::forceCreate(['id' => 0, 'name' => 'Root Team', 'personal_team' => false]);
    $server = Server::factory()->create(['team_id' => 0]);

    expect(fn () => app(DeleteTeamData::class)->{$method}($rootTeam))
        ->toThrow(LogicException::class, 'The root team (id 0) is never deleted or purged.');

    expect(Team::find(0))->not->toBeNull()
        ->and(Server::find($server->id))->not->toBeNull();
})->with(['purge', 'handle']);
