<?php

use App\Livewire\Project\Database\InitScript;
use App\Livewire\Project\Shared\Storages\VolumeBackups;
use App\Livewire\Storage\Resources as StorageResources;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\LocalPersistentVolume;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\S3Storage;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledVolumeBackup;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('auto-disables listboxes when the gate denies access', function () {
    Gate::define('update-listbox-test', fn (): bool => false);

    $html = Blade::render(<<<'BLADE'
        <x-forms.listbox id="status" :wire="false" value="enabled"
            canGate="update-listbox-test" :canResource="new stdClass"
            :options="[['value' => 'enabled', 'label' => 'Enabled']]" />
    BLADE);

    expect($html)->toMatch('/<button[^>]*id="status-trigger"[^>]*\sdisabled(?:[=\s>])/');
});

it('auto-disables checkboxes when the gate denies access', function () {
    Gate::define('update-checkbox-test', fn (): bool => false);

    $html = Blade::render(<<<'BLADE'
        <x-forms.checkbox id="replaceExisting" label="Replace objects that already exist"
            canGate="update-checkbox-test" :canResource="new stdClass" />
    BLADE);

    expect($html)
        ->toMatch('/<input[^>]*type="checkbox"[^>]*\sdisabled(?:[=\s>])/')
        ->not->toContain('canGate');
});

it('declares gate attributes on form controls with update permission checks', function () {
    $controlPattern = '/<x-forms\.(?:listbox|input|select|checkbox|textarea|button|toggle)\b.*?(?:\/>|<\/x-forms\.[^>]+>)/s';

    foreach (File::allFiles(resource_path('views')) as $file) {
        $path = $file->getPathname();
        $source = file_get_contents($path);
        preg_match_all($controlPattern, $source, $controls);

        foreach ($controls[0] as $control) {
            if (! preg_match('/can\(\s*[\'\"]update[\'\"]/', $control)) {
                continue;
            }

            expect($control, $path)->toContain('canGate="update"')
                ->toContain(':canResource=');
        }
    }
});

function actAsMutableComponentUser(User $user): void
{
    test()->actingAs($user);
    session(['currentTeam' => test()->team]);
}

describe('mutating Livewire actions', function () {
    beforeEach(function () {
        Server::flushIdentityMap();
        Queue::fake();
        InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(['id' => 0]));

        $this->team = Team::factory()->create();
        $this->admin = User::factory()->create();
        $this->admin->teams()->attach($this->team, ['role' => 'admin']);
        $this->member = User::factory()->create();
        $this->member->teams()->attach($this->team, ['role' => 'member']);

        $privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);
        $server = Server::factory()->create(['team_id' => $this->team->id, 'private_key_id' => $privateKey->id]);
        $destination = StandaloneDocker::where('server_id', $server->id)->firstOrFail();
        $project = Project::factory()->create(['team_id' => $this->team->id]);
        $environment = Environment::factory()->create(['project_id' => $project->id]);
        $this->application = Application::factory()->create([
            'environment_id' => $environment->id,
            'destination_id' => $destination->id,
            'destination_type' => $destination->getMorphClass(),
        ]);
        $this->volume = LocalPersistentVolume::create([
            'name' => 'app-data',
            'mount_path' => '/data',
            'resource_id' => $this->application->id,
            'resource_type' => $this->application->getMorphClass(),
        ]);
        $this->database = StandalonePostgresql::create([
            'name' => 'pg',
            'image' => 'postgres:16-alpine',
            'postgres_user' => 'postgres',
            'postgres_password' => 'password',
            'postgres_db' => 'postgres',
            'environment_id' => $environment->id,
            'destination_id' => $destination->id,
            'destination_type' => $destination->getMorphClass(),
        ]);
        $this->storage = S3Storage::create([
            'name' => 'Team S3',
            'region' => 'us-east-1',
            'key' => 'key',
            'secret' => 'secret',
            'bucket' => 'backups',
            'endpoint' => 'https://s3.example.com',
            'is_usable' => true,
            'team_id' => $this->team->id,
        ]);
        $this->databaseBackup = ScheduledDatabaseBackup::create([
            'frequency' => '0 0 * * *',
            'save_s3' => true,
            's3_storage_id' => $this->storage->id,
            'database_type' => $this->database->getMorphClass(),
            'database_id' => $this->database->id,
            'team_id' => $this->team->id,
        ]);
    });

    it('forbids members from removing an S3 storage from a backup schedule', function () {
        actAsMutableComponentUser($this->member);

        Livewire::test(StorageResources::class, ['storage' => $this->storage])
            ->call('disableS3', $this->databaseBackup->id)
            ->assertForbidden();

        expect($this->databaseBackup->refresh()->save_s3)->toBeTruthy()
            ->and($this->databaseBackup->s3_storage_id)->toBe($this->storage->id);
    });

    it('lets admins remove an S3 storage from a backup schedule', function () {
        actAsMutableComponentUser($this->admin);

        Livewire::test(StorageResources::class, ['storage' => $this->storage])
            ->call('disableS3', $this->databaseBackup->id)
            ->assertDispatched('success');

        expect($this->databaseBackup->refresh()->save_s3)->toBeFalsy()
            ->and($this->databaseBackup->s3_storage_id)->toBeNull();
    });

    it('forbids users of another team from opening the storage resources', function () {
        $outsider = User::factory()->create();
        $otherTeam = Team::factory()->create();
        $outsider->teams()->attach($otherTeam, ['role' => 'owner']);
        $this->actingAs($outsider);
        session(['currentTeam' => $otherTeam]);

        Livewire::test(StorageResources::class, ['storage' => $this->storage])
            ->assertForbidden();
    });

    it('does not save or delete a Postgres init script for members', function (string $action) {
        actAsMutableComponentUser($this->member);

        Livewire::test(InitScript::class, [
            'database' => $this->database,
            'script' => ['index' => 0, 'filename' => 'init.sql', 'content' => 'SELECT 1;'],
        ])
            ->call($action)
            ->assertDispatched('error')
            ->assertNotDispatched('save_init_script')
            ->assertNotDispatched('delete_init_script');
    })->with(['submit', 'delete']);

    it('saves and deletes a Postgres init script for admins', function (string $action, string $event) {
        actAsMutableComponentUser($this->admin);

        Livewire::test(InitScript::class, [
            'database' => $this->database,
            'script' => ['index' => 0, 'filename' => 'init.sql', 'content' => 'SELECT 1;'],
        ])
            ->call($action)
            ->assertDispatched($event)
            ->assertNotDispatched('error');
    })->with([
        'submit' => ['submit', 'save_init_script'],
        'delete' => ['delete', 'delete_init_script'],
    ]);

    it('forbids members from changing a volume backup schedule', function (string $action) {
        actAsMutableComponentUser($this->member);

        Livewire::test(VolumeBackups::class, ['storage' => $this->volume, 'resource' => $this->application])
            ->call($action)
            ->assertForbidden();

        expect(ScheduledVolumeBackup::count())->toBe(0);
        Queue::assertNothingPushed();
    })->with(['save', 'toggleEnabled', 'backupNow']);

    it('lets admins change a volume backup schedule', function (string $action) {
        actAsMutableComponentUser($this->admin);

        Livewire::test(VolumeBackups::class, ['storage' => $this->volume, 'resource' => $this->application])
            ->call($action)
            ->assertSuccessful();

        expect(ScheduledVolumeBackup::count())->toBe(1);
    })->with(['save', 'toggleEnabled', 'backupNow']);
});
