<?php

use App\Livewire\Project\Database\BackupEdit;
use App\Models\AgeKey;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\ScheduledDatabaseBackup;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

const ENCRYPTION_FORM_TEST_PUBLIC_KEY = 'age1qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq';

beforeEach(function () {
    Server::flushIdentityMap();
    InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(['id' => 0]));

    $this->team = Team::factory()->create();
    $this->otherTeam = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->user->teams()->attach($this->team, ['role' => 'admin']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);
    $server = Server::factory()->create(['team_id' => $this->team->id, 'private_key_id' => $privateKey->id]);
    $destination = StandaloneDocker::where('server_id', $server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $database = StandalonePostgresql::create([
        'name' => 'pg-encryption',
        'image' => 'postgres:16-alpine',
        'postgres_user' => 'postgres',
        'postgres_password' => 'password',
        'postgres_db' => 'postgres',
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
    $this->backup = ScheduledDatabaseBackup::create([
        'frequency' => '0 0 * * *',
        'save_s3' => false,
        'database_type' => $database->getMorphClass(),
        'database_id' => $database->id,
        'team_id' => $this->team->id,
    ]);
    $this->foreignKey = AgeKey::create(['name' => 'Foreign key', 'public_key' => ENCRYPTION_FORM_TEST_PUBLIC_KEY, 'team_id' => $this->otherTeam->id]);
});

it('does not enable encryption without an age key of the database team', function () {
    Livewire::test(BackupEdit::class, ['backup' => $this->backup->fresh()])
        ->assertDontSee('Foreign key')
        ->call('toggleEncryption')
        ->assertDispatched('error', 'Add an age key before enabling backup encryption.')
        ->assertSet('encryptionEnabled', false);

    expect($this->backup->fresh()->encryption_enabled)->toBeFalse();
});

it('enables encryption with the first age key of the database team', function () {
    $ownKey = AgeKey::create(['name' => 'Own key', 'public_key' => ENCRYPTION_FORM_TEST_PUBLIC_KEY, 'team_id' => $this->team->id]);

    Livewire::test(BackupEdit::class, ['backup' => $this->backup->fresh()])
        ->assertSee('Own key')
        ->call('toggleEncryption')
        ->assertDispatched('success')
        ->assertSet('encryptionEnabled', true)
        ->assertSet('ageKeyId', $ownKey->id);

    $backup = $this->backup->fresh();
    expect($backup->encryption_enabled)->toBeTrue()
        ->and($backup->age_key_id)->toBe($ownKey->id)
        ->and($ownKey->isInUse())->toBeTrue();
});

it('replaces an age key of another team with a key of the database team', function () {
    $ownKey = AgeKey::create(['name' => 'Own key', 'public_key' => ENCRYPTION_FORM_TEST_PUBLIC_KEY, 'team_id' => $this->team->id]);
    $this->backup->update(['encryption_enabled' => true, 'age_key_id' => $ownKey->id]);

    Livewire::test(BackupEdit::class, ['backup' => $this->backup->fresh()])
        ->set('ageKeyId', $this->foreignKey->id)
        ->assertSet('ageKeyId', $ownKey->id);

    expect($this->backup->fresh()->age_key_id)->toBe($ownKey->id);
});

it('lets a key be deleted once no schedule encrypts with it', function () {
    $ownKey = AgeKey::create(['name' => 'Own key', 'public_key' => ENCRYPTION_FORM_TEST_PUBLIC_KEY, 'team_id' => $this->team->id]);
    $this->backup->update(['encryption_enabled' => true, 'age_key_id' => $ownKey->id]);

    Livewire::test(BackupEdit::class, ['backup' => $this->backup->fresh()])
        ->call('toggleEncryption')
        ->assertSet('encryptionEnabled', false);

    expect($ownKey->isInUse())->toBeFalse();

    $ownKey->delete();

    expect($this->backup->fresh()->age_key_id)->toBeNull();
});
