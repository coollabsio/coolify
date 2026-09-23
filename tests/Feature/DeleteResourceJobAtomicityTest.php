<?php

use App\Actions\Service\DeleteService;
use App\Jobs\DeleteResourceJob;
use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\ScheduledVolumeBackup;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Notifications\Internal\GeneralNotification;
use Illuminate\Foundation\Console\QueuedCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(['id' => 0]));

    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $destination = StandaloneDocker::where('server_id', $server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->first()
        ?? Environment::factory()->create(['project_id' => $project->id]);

    $this->application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
    $this->storage = $this->application->persistentStorages()->create([
        'name' => 'delete-resource-job-test',
        'mount_path' => '/data',
        'host_path' => null,
    ]);

    $this->application->delete();
    Queue::fake();
});

it('deletes a non-service Coolify resource when remote cleanup fails', function () {
    Process::fake(['*' => Process::result(errorOutput: 'SSH connection timed out', exitCode: 255)]);

    (new DeleteResourceJob($this->application))->handle();

    expect(Application::withTrashed()->find($this->application->id))->toBeNull();
    Queue::assertNotPushed(QueuedCommand::class);
});

it('keeps a service when remote cleanup fails', function () {
    Notification::fake();
    $service = Service::factory()->create([
        'environment_id' => $this->application->environment_id,
        'server_id' => $this->application->destination->server_id,
        'destination_id' => $this->application->destination_id,
        'destination_type' => $this->application->destination_type,
    ]);
    ServiceApplication::create([
        'service_id' => $service->id,
        'name' => 'web',
        'image' => 'nginx:alpine',
    ]);
    $service->server->team->webhookNotificationSettings()->update([
        'webhook_enabled' => true,
        'webhook_url' => 'https://example.com/webhook',
    ]);
    $service->delete();
    Process::fake();

    expect(fn () => (new DeleteResourceJob($service))->handle())
        ->toThrow(RuntimeException::class, 'Server is not functional.');

    expect(Service::find($service->id))->not->toBeNull()
        ->and(ServiceApplication::where('service_id', $service->id)->exists())->toBeTrue();
    Notification::assertCount(1);
    Notification::assertSentTo(
        $service->team(),
        GeneralNotification::class,
        fn (GeneralNotification $notification): bool => ! $notification->success
            && str_contains($notification->message, 'Remove from Coolify only')
    );
});

it('deletes only local service metadata when explicitly requested', function () {
    $service = Service::factory()->create([
        'environment_id' => $this->application->environment_id,
        'server_id' => $this->application->destination->server_id,
        'destination_id' => $this->application->destination_id,
        'destination_type' => $this->application->destination_type,
    ]);
    ServiceApplication::create([
        'service_id' => $service->id,
        'name' => 'web',
        'image' => 'nginx:alpine',
    ]);
    Process::fake();

    (new DeleteResourceJob($service, deleteFromCoolifyOnly: true))->handle();

    Process::assertNothingRan();
    expect(Service::withTrashed()->find($service->id))->toBeNull()
        ->and(ServiceApplication::withTrashed()->where('service_id', $service->id)->exists())->toBeFalse();
});

it('removes service containers before its volumes and local metadata', function () {
    $service = Service::factory()->create([
        'environment_id' => $this->application->environment_id,
        'server_id' => $this->application->destination->server_id,
        'destination_id' => $this->application->destination_id,
        'destination_type' => $this->application->destination_type,
    ]);
    $application = ServiceApplication::create([
        'service_id' => $service->id,
        'name' => 'web',
        'image' => 'nginx:alpine',
    ]);
    $application->persistentStorages()->create([
        'name' => "{$service->uuid}_web-data",
        'mount_path' => '/data',
        'host_path' => null,
    ]);
    $privateKey = PrivateKey::factory()->create(['team_id' => $service->server->team_id]);
    $service->server->update(['private_key_id' => $privateKey->id]);
    $service->server->settings()->update(['is_reachable' => true, 'is_usable' => true]);
    $commands = collect();
    Process::fake(function ($process) use ($commands) {
        $commands->push($process->command);

        return Process::result(output: '');
    });

    (new DeleteResourceJob($service))->handle();

    $commandList = $commands->implode("\n");
    expect($commandList)
        ->toContain("label=coolify.serviceId={$service->id}")
        ->toContain('docker rm -f $container_ids')
        ->toContain("docker volume rm -f '{$service->uuid}_web-data'")
        ->and(strpos($commandList, 'docker rm -f $container_ids'))
        ->toBeLessThan(strpos($commandList, 'docker volume rm -f'));
    expect(Service::withTrashed()->find($service->id))->toBeNull();
});

it('targets a service subresource container by its Docker labels', function () {
    $service = Service::factory()->create([
        'environment_id' => $this->application->environment_id,
        'server_id' => $this->application->destination->server_id,
        'destination_id' => $this->application->destination_id,
        'destination_type' => $this->application->destination_type,
    ]);
    $application = ServiceApplication::create([
        'service_id' => $service->id,
        'name' => 'web',
        'image' => 'nginx:alpine',
    ]);
    $privateKey = PrivateKey::factory()->create(['team_id' => $service->server->team_id]);
    $service->server->update(['private_key_id' => $privateKey->id]);
    $service->server->settings()->update(['is_reachable' => true, 'is_usable' => true]);
    $commands = collect();
    Process::fake(function ($process) use ($commands) {
        $commands->push($process->command);

        return Process::result(output: '');
    });

    app(DeleteService::class)->removeSubresourceContainer($application);

    expect($commands->implode("\n"))
        ->toContain("label=coolify.serviceId={$service->id}")
        ->toContain("label=coolify.service.subId={$application->id}")
        ->toContain('docker rm -f $container_ids');
});

it('rolls back local metadata deletion when deleting the resource fails', function () {
    Process::fake(['*' => Process::result(output: '')]);
    $applicationUuid = $this->application->uuid;
    Application::deleting(function (Application $application) use ($applicationUuid): void {
        if ($application->uuid === $applicationUuid && $application->isForceDeleting()) {
            throw new RuntimeException('Local deletion failed.');
        }
    });

    expect(fn () => (new DeleteResourceJob($this->application))->handle())
        ->toThrow(RuntimeException::class, 'Local deletion failed.');

    expect($this->storage->fresh())->not->toBeNull()
        ->and(Application::withTrashed()->find($this->application->id))->not->toBeNull();
});

it('deletes scheduled volume backups outside the local deletion transaction', function () {
    $backup = $this->storage->scheduledBackups()->create([
        'team_id' => $this->application->environment->project->team_id,
        'frequency' => 'daily',
        'timeout' => 3600,
    ]);
    $transactionLevel = DB::transactionLevel();
    $deletingTransactionLevel = null;

    ScheduledVolumeBackup::deleting(function (ScheduledVolumeBackup $deletingBackup) use ($backup, &$deletingTransactionLevel): void {
        if ($deletingBackup->is($backup)) {
            $deletingTransactionLevel = DB::transactionLevel();
        }
    });
    Process::fake(['*' => Process::result(output: '')]);

    (new DeleteResourceJob($this->application))->handle();

    expect($deletingTransactionLevel)->toBe($transactionLevel);
});

it('deletes preview metadata locally when its application destination is missing', function () {
    $this->application->restore();
    $this->application->update(['destination_id' => PHP_INT_MAX]);
    $preview = ApplicationPreview::create([
        'uuid' => 'preview-without-destination',
        'application_id' => $this->application->id,
        'pull_request_id' => 45,
        'pull_request_html_url' => 'https://github.com/coollabsio/coolify/pull/45',
    ]);
    $previewStorage = $preview->persistentStorages()->create([
        'name' => 'preview-without-destination-data',
        'mount_path' => '/preview-data',
        'host_path' => null,
    ]);
    Process::fake();

    (new DeleteResourceJob($preview))->handle();

    Process::assertNothingRan();
    expect(ApplicationPreview::withTrashed()->find($preview->id))->toBeNull()
        ->and($previewStorage->fresh())->toBeNull();
});
