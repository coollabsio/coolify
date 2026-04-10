<?php

use App\Actions\Terminal\DeleteTerminalUploadedFile;
use App\Enums\TerminalUploadedFileStatus;
use App\Livewire\Terminal\FileImport;
use App\Livewire\Terminal\Index as TerminalIndex;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
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

it('shows the import file button even when no terminal target is selected', function () {
    $privateKey = PrivateKey::create([
        'name' => 'Test Key',
        'private_key' => <<<'KEY'
-----BEGIN OPENSSH PRIVATE KEY-----
b3BlbnNzaC1rZXktdjEAAAAABG5vbmUAAAAEbm9uZQAAAAAAAAABAAAAMwAAAAtzc2gtZW
QyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevAAAAJi/QySHv0Mk
hwAAAAtzc2gtZWQyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevA
AAAECBQw4jg1WRT2IGHMncCiZhURCts2s24HoDS0thHnnRKVuGmoeGq/pojrsyP1pszcNV
uZx9iFkCELtxrh31QJ68AAAAEXNhaWxANzZmZjY2ZDJlMmRkAQIDBA==
-----END OPENSSH PRIVATE KEY-----
KEY,
        'team_id' => $this->team->id,
    ]);

    $server = Server::factory()->create([
        'name' => 'Terminal Server',
        'ip' => 'coolify-terminal-test-host',
        'team_id' => $this->team->id,
        'private_key_id' => $privateKey->id,
    ]);

    $server->settings()->update([
        'is_reachable' => true,
        'is_terminal_enabled' => true,
    ]);

    Livewire::test(TerminalIndex::class)
        ->call('loadContainers')
        ->assertSet('selected_uuid', 'default')
        ->assertSee('Import File');
});

it('requires selecting a server or container before finalizing an uploaded file', function () {
    Livewire::test(FileImport::class, [
        'selectedUuid' => 'default',
        'servers' => [
            ['uuid' => 'server-uuid', 'name' => 'Server One'],
        ],
        'containers' => [
            ['uuid' => 'container-uuid', 'name' => 'App Container', 'server_uuid' => 'server-uuid'],
        ],
    ])
        ->set('hasPendingUpload', true)
        ->set('filename', 'dump.sql')
        ->call('generateFilePath')
        ->assertDispatched('error', 'Please select a server or container.');
});

it('renders the shared modal confirmation for deleting uploaded files', function () {
    TerminalUploadedFile::query()->create([
        'user_id' => $this->owner->id,
        'team_id' => $this->team->id,
        'upload_token' => 'render-delete-modal-token',
        'original_name' => 'database-dump.sql',
        'stored_filename' => 'stored-database-dump.sql',
        'mime_type' => 'application-sql',
        'size_bytes' => 1024,
        'local_path' => storage_path('app/terminal-uploads/user_1_render-delete-modal/stored-database-dump.sql'),
        'server_path' => '/tmp/stored-database-dump.sql',
        'container_path' => null,
        'status' => TerminalUploadedFileStatus::Active,
        'uploaded_at' => now(),
        'expires_at' => now()->addHour(),
        'finalized_at' => now(),
    ]);

    $component = Livewire::test(FileImport::class, [
        'selectedUuid' => 'server-uuid',
        'servers' => [
            ['uuid' => 'server-uuid', 'name' => 'Server One'],
        ],
        'containers' => [],
    ])
        ->assertSee('Confirm Terminal File Deletion?')
        ->assertSee('Please confirm the deletion by entering the uploaded file name below');

    expect($component->html())
        ->toContain('confirmDeleteFile')
        ->not->toContain('wire:confirm=');
});

it('prepares and confirms terminal upload deletion through the shared modal flow', function () {
    $terminalUploadedFile = TerminalUploadedFile::query()->create([
        'user_id' => $this->owner->id,
        'team_id' => $this->team->id,
        'upload_token' => 'confirm-delete-modal-token',
        'original_name' => 'database-dump.sql',
        'stored_filename' => 'stored-database-dump.sql',
        'mime_type' => 'application-sql',
        'size_bytes' => 1024,
        'local_path' => storage_path('app/terminal-uploads/user_1_confirm-delete-modal/stored-database-dump.sql'),
        'server_path' => '/tmp/stored-database-dump.sql',
        'container_path' => null,
        'status' => TerminalUploadedFileStatus::Active,
        'uploaded_at' => now(),
        'expires_at' => now()->addHour(),
        'finalized_at' => now(),
    ]);

    $deleteTerminalUploadedFile = Mockery::mock(DeleteTerminalUploadedFile::class);
    $deleteTerminalUploadedFile->shouldReceive('__invoke')
        ->once()
        ->withArgs(function (TerminalUploadedFile $file) use ($terminalUploadedFile): bool {
            return $file->is($terminalUploadedFile);
        });

    app()->instance(DeleteTerminalUploadedFile::class, $deleteTerminalUploadedFile);

    Livewire::test(FileImport::class, [
        'selectedUuid' => 'server-uuid',
        'servers' => [
            ['uuid' => 'server-uuid', 'name' => 'Server One'],
        ],
        'containers' => [],
    ])
        ->call('prepareFileDeletion', $terminalUploadedFile->uuid)
        ->assertSet('pendingDeleteFileUuid', $terminalUploadedFile->uuid)
        ->call('confirmDeleteFile')
        ->assertSet('pendingDeleteFileUuid', null)
        ->assertDispatched('success', 'File deleted successfully!');
});
