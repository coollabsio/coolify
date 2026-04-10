<?php

use App\Enums\TerminalUploadedFileStatus;
use App\Livewire\Terminal\FileImport;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\TerminalUploadedFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(function () {
        InstanceSettings::query()->create(['id' => 0]);
    });

    $this->team = Team::factory()->create();
    $this->owner = User::factory()->create();
    $this->owner->teams()->attach($this->team, ['role' => 'owner']);

    $this->actingAs($this->owner);
    session(['currentTeam' => $this->team]);
});

it('rejects copying terminal upload paths outside the current team', function () {
    $otherTeam = Team::factory()->create();
    $otherOwner = User::factory()->create();
    $otherOwner->teams()->attach($otherTeam, ['role' => 'owner']);

    $terminalUploadedFile = TerminalUploadedFile::query()->create([
        'user_id' => $otherOwner->id,
        'team_id' => $otherTeam->id,
        'upload_token' => 'foreign-upload-token',
        'original_name' => 'foreign.txt',
        'stored_filename' => 'stored-foreign.txt',
        'mime_type' => 'text-plain',
        'size_bytes' => 128,
        'local_path' => storage_path('app/terminal-uploads/user_999_foreign/stored-foreign.txt'),
        'server_path' => '/tmp/stored-foreign.txt',
        'container_path' => null,
        'status' => TerminalUploadedFileStatus::Active,
        'uploaded_at' => now(),
        'expires_at' => now()->addHour(),
        'finalized_at' => now(),
    ]);

    Livewire::test(FileImport::class, [
        'selectedUuid' => 'server-uuid',
        'servers' => [
            ['uuid' => 'server-uuid', 'name' => 'Server'],
        ],
        'containers' => [],
    ])
        ->call('copyPath', $terminalUploadedFile->uuid)
        ->assertDispatched('error');
});

it('rejects preparing terminal upload deletions outside the current team', function () {
    $otherTeam = Team::factory()->create();
    $otherOwner = User::factory()->create();
    $otherOwner->teams()->attach($otherTeam, ['role' => 'owner']);

    $terminalUploadedFile = TerminalUploadedFile::query()->create([
        'user_id' => $otherOwner->id,
        'team_id' => $otherTeam->id,
        'upload_token' => 'foreign-prepare-delete-token',
        'original_name' => 'foreign.txt',
        'stored_filename' => 'stored-foreign.txt',
        'mime_type' => 'text-plain',
        'size_bytes' => 128,
        'local_path' => storage_path('app/terminal-uploads/user_999_foreign/stored-foreign.txt'),
        'server_path' => '/tmp/stored-foreign.txt',
        'container_path' => null,
        'status' => TerminalUploadedFileStatus::Active,
        'uploaded_at' => now(),
        'expires_at' => now()->addHour(),
        'finalized_at' => now(),
    ]);

    Livewire::test(FileImport::class, [
        'selectedUuid' => 'server-uuid',
        'servers' => [
            ['uuid' => 'server-uuid', 'name' => 'Server'],
        ],
        'containers' => [],
    ])
        ->call('prepareFileDeletion', $terminalUploadedFile->uuid)
        ->assertSet('pendingDeleteFileUuid', null)
        ->assertDispatched('error');
});

it('rejects deleting terminal uploads outside the current team', function () {
    $otherTeam = Team::factory()->create();
    $otherOwner = User::factory()->create();
    $otherOwner->teams()->attach($otherTeam, ['role' => 'owner']);

    $terminalUploadedFile = TerminalUploadedFile::query()->create([
        'user_id' => $otherOwner->id,
        'team_id' => $otherTeam->id,
        'upload_token' => 'foreign-delete-token',
        'original_name' => 'foreign.txt',
        'stored_filename' => 'stored-foreign.txt',
        'mime_type' => 'text-plain',
        'size_bytes' => 128,
        'local_path' => storage_path('app/terminal-uploads/user_999_foreign/stored-foreign.txt'),
        'server_path' => '/tmp/stored-foreign.txt',
        'container_path' => null,
        'status' => TerminalUploadedFileStatus::Active,
        'uploaded_at' => now(),
        'expires_at' => now()->addHour(),
        'finalized_at' => now(),
    ]);

    Livewire::test(FileImport::class, [
        'selectedUuid' => 'server-uuid',
        'servers' => [
            ['uuid' => 'server-uuid', 'name' => 'Server'],
        ],
        'containers' => [],
    ])
        ->call('deleteFile', $terminalUploadedFile->uuid)
        ->assertDispatched('error');
});

it('requires a visible terminal upload before confirming deletion', function () {
    Livewire::test(FileImport::class, [
        'selectedUuid' => 'server-uuid',
        'servers' => [
            ['uuid' => 'server-uuid', 'name' => 'Server'],
        ],
        'containers' => [],
    ])
        ->call('confirmDeleteFile')
        ->assertSet('pendingDeleteFileUuid', null)
        ->assertDispatched('error', 'File not found.');
});
