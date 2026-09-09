<?php

use App\Jobs\DatabaseBackupJob;
use App\Livewire\Project\Database\BackupEdit;
use App\Livewire\Project\Database\BackupNow;
use App\Livewire\Project\Service\VolumeBackup\Index;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\ScheduledDatabaseBackup;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceDatabase;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $user->teams()->attach($team, ['role' => 'owner']);
    $this->actingAs($user);
    session(['currentTeam' => $team]);
    $server = Server::factory()->create(['team_id' => $team->id]);
    $destination = StandaloneDocker::where('server_id', $server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $resourceAttributes = [
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ];
    $this->service = Service::factory()->create(['server_id' => $server->id, ...$resourceAttributes]);
    $this->databases = [
        'standalone' => StandalonePostgresql::create([
            ...$resourceAttributes,
            'name' => 'postgres',
            'postgres_password' => 'password',
        ]),
        'service' => ServiceDatabase::create([
            'service_id' => $this->service->id,
            'name' => 'postgres',
            'image' => 'postgres:16-alpine',
            'custom_type' => 'postgresql',
        ]),
    ];
    $this->backups = collect($this->databases)->map(fn ($database) => ScheduledDatabaseBackup::create([
        'team_id' => $team->id,
        'frequency' => 'daily',
        'database_id' => $database->id,
        'database_type' => $database->getMorphClass(),
    ]));
    Queue::fake();
});

dataset('database backup controls', [
    'standalone settings' => [BackupEdit::class, 'standalone'],
    'service settings' => [BackupEdit::class, 'service'],
    'standalone button' => [BackupNow::class, 'standalone'],
    'service button' => [BackupNow::class, 'service'],
    'service list' => [Index::class, 'service'],
]);

it('only enables and queues database backups for running targets', function (string $componentClass, string $type, string $status, bool $running) {
    $database = $this->databases[$type];
    $database->update(['status' => $status]);
    $backup = $this->backups[$type]->fresh();
    $parameters = $componentClass === Index::class
        ? ['service' => $this->service]
        : ['backup' => $backup, ...($componentClass === BackupEdit::class ? ['availableS3Storages' => collect()] : [])];
    $component = Livewire::test($componentClass, $parameters);
    $dom = new DOMDocument;
    @$dom->loadHTML($component->html());
    $buttons = (new DOMXPath($dom))->query('//button');
    $backupButtons = [];
    foreach ($buttons as $button) {
        if (str_starts_with($button->getAttribute('wire:click'), 'backupNow') || str_starts_with($button->getAttribute('wire:click.stop'), 'backupNow')) {
            $backupButtons[] = $button;
        }
    }
    expect($backupButtons)->toHaveCount(1);
    expect($backupButtons[0]->hasAttribute('disabled'))->toBe(! $running);

    $component->call('backupNow', ...($componentClass === Index::class ? ['database', $backup->uuid] : []));
    if ($running) {
        Queue::assertPushed(DatabaseBackupJob::class);
    } else {
        $component->assertDispatched('error')->assertNotDispatched('success')->assertNoRedirect();
        Queue::assertNotPushed(DatabaseBackupJob::class);
    }
})->with('database backup controls')->with([
    ['running:healthy', true],
    ['running:unhealthy', true],
    ['exited:unhealthy', false],
    ['restarting:unhealthy', false],
]);

it('checks current database status before queuing from a stale page', function (string $componentClass, string $type) {
    $database = $this->databases[$type];
    $database->update(['status' => 'running:healthy']);
    $backup = $this->backups[$type]->fresh();
    $parameters = $componentClass === Index::class
        ? ['service' => $this->service]
        : ['backup' => $backup, ...($componentClass === BackupEdit::class ? ['availableS3Storages' => collect()] : [])];
    $component = Livewire::test($componentClass, $parameters);
    $database->update(['status' => 'exited:unhealthy']);

    $component->call('backupNow', ...($componentClass === Index::class ? ['database', $backup->uuid] : []))
        ->assertDispatched('error')->assertNotDispatched('success')->assertNoRedirect();
    Queue::assertNotPushed(DatabaseBackupJob::class);
})->with('database backup controls');

it('refreshes service backup buttons when the service status check completes', function () {
    $database = $this->databases['service'];
    $database->update(['status' => 'exited:unhealthy']);
    $component = Livewire::test(Index::class, ['service' => $this->service]);
    $database->update(['status' => 'running:healthy']);

    $component->dispatch('echo-private:team.'.currentTeam()->id.',ServiceChecked');
    expect($component->html())->not->toMatch('/<button\s+disabled[^>]*wire:click.stop="backupNow/s');

    $database->update(['status' => 'exited:unhealthy']);
    $component->dispatch('echo-private:team.'.currentTeam()->id.',ServiceChecked');
    expect($component->html())->toMatch('/<button\s+disabled[^>]*wire:click.stop="backupNow/s');
});
