<?php

use App\Events\BackupCreated;
use App\Jobs\DatabaseBackupJob;
use App\Jobs\SendMessageToTelegramJob;
use App\Jobs\SendWebhookJob;
use App\Jobs\VolumeBackupJob;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\LocalPersistentVolume;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\S3Storage;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledDatabaseBackupExecution;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\StandaloneMysql;
use App\Models\Team;
use App\Models\User;
use App\Notifications\Database\BackupFailed;
use App\Notifications\Internal\GeneralNotification;
use App\Notifications\VolumeBackup\BackupFailed as VolumeBackupFailed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    Storage::fake('ssh-keys');
    Storage::fake('ssh-mux');
    config(['constants.ssh.mux_enabled' => false]);
    Event::fake([BackupCreated::class]);
    Notification::fake();
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->firstOrCreate(['id' => 0], ['is_api_enabled' => true]));
    InstanceSettings::query()->whereKey(0)->update(['is_api_enabled' => true]);

    $this->team = Team::factory()->create();
    // The notification fake only records notifications that have at least one enabled channel.
    $this->team->emailNotificationSettings->update(['smtp_enabled' => true, 'backup_failure_email_notifications' => true]);
    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $this->team->id])->id,
    ]);
    $this->server->settings()->update(['is_reachable' => true, 'is_usable' => true]);
    $this->destination = StandaloneDocker::where('server_id', $this->server->id)->firstOrFail();
    $this->environment = Environment::factory()->create(['project_id' => Project::factory()->create(['team_id' => $this->team->id])->id]);
    $this->s3 = S3Storage::create([
        'name' => 'Backups',
        'region' => 'us-east-1',
        'key' => 'redaction-s3-access-key',
        'secret' => 'redaction-s3-secret-value',
        'bucket' => 'bucket',
        'endpoint' => 'https://s3.example.com',
        'team_id' => $this->team->id,
        'is_usable' => true,
    ]);
});

function redactionMysqlBackup(Team $team, Environment $environment, StandaloneDocker $destination, ?S3Storage $s3 = null): ScheduledDatabaseBackup
{
    $database = create_standalone_mysql($environment->id, $destination, [
        'mysql_root_password' => str_repeat('x', 5),
        'mysql_database' => 'app',
        'status' => 'running:healthy',
    ]);

    return ScheduledDatabaseBackup::create([
        'frequency' => '0 0 * * *',
        'save_s3' => $s3 !== null,
        's3_storage_id' => $s3?->id,
        'database_type' => StandaloneMysql::class,
        'database_id' => $database->id,
        'team_id' => $team->id,
        'databases_to_backup' => 'app',
    ]);
}

/**
 * @return array<string, string>
 */
function backupRedactionToken(User $user, Team $team, array $abilities): array
{
    session(['currentTeam' => $team]);
    $token = $user->createToken('backup-redaction', $abilities);
    DB::table('personal_access_tokens')->where('id', $token->accessToken->id)->update(['team_id' => $team->id]);

    return ['Authorization' => 'Bearer '.$token->plainTextToken];
}

it('redacts the database password from the stored execution and the failure notification', function () {
    $backup = redactionMysqlBackup($this->team, $this->environment, $this->destination);
    $password = $backup->database->mysql_root_password;
    Process::fake(function ($process) use ($password) {
        $command = is_array($process->command) ? implode(' ', $process->command) : $process->command;
        if (str_contains($command, 'mysqldump')) {
            return Process::result(errorOutput: "mysqldump: Got error: 1045: Access denied for user 'root' with password {$password}", exitCode: 1);
        }

        return Process::result(output: '');
    });

    (new DatabaseBackupJob($backup))->handle();

    $execution = $backup->executions()->latest('id')->firstOrFail();
    expect($execution->status)->toBe('failed')
        ->and($execution->message)->toContain('Access denied')
        ->and($execution->message)->not->toContain($password);
    Notification::assertSentTo($this->team, BackupFailed::class, fn (BackupFailed $notification) => str_contains((string) $notification->output, 'Access denied')
        && ! str_contains((string) $notification->output, $password));
});

it('redacts the S3 key and secret from a permanently failed database backup', function () {
    $backup = redactionMysqlBackup($this->team, $this->environment, $this->destination, $this->s3);
    ScheduledDatabaseBackupExecution::create([
        'uuid' => 'redaction-run',
        'scheduled_database_backup_id' => $backup->id,
        'database_name' => 'app',
        'status' => 'running',
    ]);
    $job = new DatabaseBackupJob($backup);
    $job->executionUuidPrefix = 'redaction-run';

    $job->failed(new RuntimeException('mc: alias set failed for key redaction-s3-access-key and secret redaction-s3-secret-value'));

    $message = $backup->executions()->sole()->message;
    expect($message)->toContain('mc: alias set failed')
        ->and($message)->not->toContain('redaction-s3-access-key')
        ->and($message)->not->toContain('redaction-s3-secret-value');
    Notification::assertSentTo($this->team, BackupFailed::class, fn (BackupFailed $notification) => ! str_contains((string) $notification->output, 'redaction-s3-secret-value')
        && ! str_contains((string) $notification->output, 'redaction-s3-access-key'));
});

it('redacts the S3 key and secret from a failed volume backup', function () {
    $application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);
    $volume = LocalPersistentVolume::create([
        'name' => 'app-data',
        'mount_path' => '/data',
        'resource_id' => $application->id,
        'resource_type' => $application->getMorphClass(),
    ]);
    $backup = $volume->scheduledBackups()->create([
        'team_id' => $this->team->id,
        's3_storage_id' => $this->s3->id,
        'save_s3' => true,
        'frequency' => '0 0 * * *',
    ]);
    $execution = $backup->executions()->create(['s3_storage_id' => $this->s3->id]);

    (new VolumeBackupJob($backup))->failed(new RuntimeException('mc: upload failed with secret redaction-s3-secret-value'));

    expect($execution->fresh()->message)->toContain('mc: upload failed')
        ->and($execution->fresh()->message)->not->toContain('redaction-s3-secret-value');
    Notification::assertSentTo($this->team, VolumeBackupFailed::class, fn (VolumeBackupFailed $notification) => str_contains($notification->output, 'mc: upload failed')
        && ! str_contains($notification->output, 'redaction-s3-secret-value'));
});

it('does not put the telegram bot token into the connection error', function () {
    Http::fake(['*' => Http::failedConnection()]);

    try {
        (new SendMessageToTelegramJob('Message', [], '123456:telegram-bot-secret-token', '42'))->handle();
        $this->fail('The connection error was not reported.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toContain('https://api.telegram.org')
            ->and($exception->getMessage())->not->toContain('telegram-bot-secret-token')
            ->and($exception->getPrevious())->toBeNull();
    }
});

it('does not put the secret webhook path into the connection error', function () {
    Http::fake(['*' => Http::failedConnection()]);

    try {
        (new SendWebhookJob(['event' => 'test'], 'https://example.com/hooks/webhook-secret-path'))->handle();
        $this->fail('The connection error was not reported.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toContain('https://example.com')
            ->and($exception->getMessage())->not->toContain('webhook-secret-path')
            ->and($exception->getPrevious())->toBeNull();
    }
});

it('does not share the provider response body when a channel is disabled', function () {
    $rootTeam = Team::find(0) ?? Team::factory()->create(['id' => 0]);
    foreach ([$rootTeam, $this->team] as $team) {
        $team->discordNotificationSettings->update(['discord_enabled' => true, 'discord_webhook_url' => 'https://example.com/discord']);
    }
    $settings = tap($this->team->telegramNotificationSettings)->update(['telegram_enabled' => true, 'telegram_token' => 'token', 'telegram_chat_id' => '123']);
    Http::fake(['*' => Http::response(['ok' => false, 'description' => 'Unauthorized provider-body-marker'], 401)]);

    foreach (range(1, 3) as $attempt) {
        (new SendMessageToTelegramJob('Message', [], 'token', '123', null, $settings->fresh()))->handle();
    }

    expect($settings->fresh()->telegram_enabled)->toBeFalse();
    Notification::assertSentTo($rootTeam, GeneralNotification::class, fn (GeneralNotification $notification) => str_contains($notification->message, 'HTTP 401')
        && ! str_contains($notification->message, 'provider-body-marker'));
    Notification::assertSentTo($this->team, GeneralNotification::class, fn (GeneralNotification $notification) => ! str_contains($notification->message, 'provider-body-marker'));
});

it('redacts secrets from server validation logs', function () {
    $this->server->update(['validation_logs' => 'Could not connect: postgres://coolify:validation-db-secret@10.0.0.5/app']);

    expect($this->server->fresh()->validation_logs)->toContain('Could not connect')
        ->and($this->server->fresh()->validation_logs)->not->toContain('validation-db-secret');
});

describe('backup executions api', function () {
    beforeEach(function () {
        $this->owner = User::factory()->create();
        $this->team->members()->attach($this->owner, ['role' => 'owner']);
        $this->backup = redactionMysqlBackup($this->team, $this->environment, $this->destination);
        ScheduledDatabaseBackupExecution::create([
            'scheduled_database_backup_id' => $this->backup->id,
            'database_name' => 'app',
            'status' => 'failed',
            'message' => 'dump failed: mysql://root:api-db-secret@db:3306/app',
        ]);
        $this->executionsUrl = "/api/v1/databases/{$this->backup->database->uuid}/backups/{$this->backup->uuid}/executions";
        $this->backupsUrl = "/api/v1/databases/{$this->backup->database->uuid}/backups";
    });

    it('hides the execution message from a token without read:sensitive', function () {
        $headers = backupRedactionToken($this->owner, $this->team, ['read']);

        $executions = $this->withHeaders($headers)->getJson($this->executionsUrl)->assertOk();
        $backups = $this->withHeaders($headers)->getJson($this->backupsUrl)->assertOk();

        expect($executions->json('executions.0.status'))->toBe('failed')
            ->and($executions->json('executions.0.message'))->toBeNull()
            ->and($backups->json('0.executions.0.message'))->toBeNull()
            ->and($executions->getContent().$backups->getContent())->not->toContain('dump failed');
    });

    it('returns the redacted execution message to a token with read:sensitive', function () {
        $headers = backupRedactionToken($this->owner, $this->team, ['read', 'read:sensitive']);

        $message = $this->withHeaders($headers)->getJson($this->executionsUrl)->assertOk()->json('executions.0.message');

        expect($message)->toContain('dump failed')
            ->and($message)->not->toContain('api-db-secret');
    });

    it('does not return executions of another team', function () {
        $otherTeam = Team::factory()->create();
        $otherTeam->members()->attach($this->owner, ['role' => 'owner']);
        $headers = backupRedactionToken($this->owner, $otherTeam, ['read', 'read:sensitive']);

        $this->withHeaders($headers)->getJson($this->executionsUrl)->assertNotFound();
    });
});
