<?php

use App\Livewire\Storage\Create;
use App\Livewire\Storage\Form;
use App\Models\InstanceSettings;
use App\Models\S3Storage;
use App\Models\Team;
use App\Models\User;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);

    $this->team = Team::factory()->create();
    $this->owner = User::factory()->create();
    $this->team->members()->attach($this->owner, ['role' => 'owner']);

    $this->actingAs($this->owner);
    session(['currentTeam' => $this->team]);
});

it('rejects a username and password in the server of a new SFTP storage', function () {
    Livewire::test(Create::class)
        ->set('type', 'sftp')
        ->set('name', 'SFTP storage')
        ->set('key', 'backup')
        ->set('secret', 'password')
        ->set('serverParts.host', 'user:secret@1.1.1.1')
        ->call('submit')
        ->assertDispatched('error', fn (string $event, array $params): bool => str_contains($params[1], 'The Server must not contain a username or password.'));

    expect(S3Storage::query()->count())->toBe(0);
});

it('saves a storage whose saved endpoint already has a username and password', function () {
    if (! defined('CURLOPT_RESOLVE')) {
        define('CURLOPT_RESOLVE', 10203);
    }
    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->allows('files')->andReturn([]);
    Storage::shouldReceive('build')->andReturn($disk);

    $storage = S3Storage::create([
        'team_id' => $this->team->id,
        'name' => 'Old name',
        'region' => 'us-east-1',
        'key' => 'access-key',
        'secret' => 'secret-key',
        'bucket' => 'backups',
        'endpoint' => 'https://user:secret@1.1.1.1',
        'is_usable' => true,
    ]);

    Livewire::test(Form::class, ['storage' => $storage])
        ->set('name', 'New name')
        ->call('submit')
        ->assertHasNoErrors();

    expect($storage->fresh()->name)->toBe('New name');
});
