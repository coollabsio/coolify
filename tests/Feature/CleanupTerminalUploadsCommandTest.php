<?php

use App\Enums\TerminalUploadedFileStatus;
use App\Models\Team;
use App\Models\TerminalUploadedFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('cleans expired and stale pending terminal uploads from the database and filesystem', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $user->teams()->attach($team, ['role' => 'owner']);

    $expiredDirectory = storage_path('app/terminal-uploads/user_'.$user->id.'_expired-token');
    $pendingDirectory = storage_path('app/terminal-uploads-pending/user_'.$user->id.'/pending-token');

    mkdir($expiredDirectory, 0755, true);
    mkdir($pendingDirectory, 0755, true);

    $expiredPath = $expiredDirectory.'/expired-file.txt';
    $pendingPath = $pendingDirectory.'/pending-file.txt';

    file_put_contents($expiredPath, 'expired');
    file_put_contents($pendingPath, 'pending');

    $expiredUpload = TerminalUploadedFile::query()->create([
        'user_id' => $user->id,
        'team_id' => $team->id,
        'upload_token' => 'expired-token',
        'original_name' => 'expired.txt',
        'stored_filename' => 'expired-file.txt',
        'mime_type' => 'text-plain',
        'size_bytes' => 7,
        'local_path' => $expiredPath,
        'server_path' => null,
        'container_path' => null,
        'status' => TerminalUploadedFileStatus::Active,
        'uploaded_at' => now()->subHours(2),
        'expires_at' => now()->subMinute(),
        'finalized_at' => now()->subHour(),
    ]);

    $pendingUpload = TerminalUploadedFile::query()->create([
        'user_id' => $user->id,
        'team_id' => $team->id,
        'upload_token' => 'pending-token',
        'original_name' => 'pending.txt',
        'stored_filename' => 'pending-file.txt',
        'mime_type' => 'text-plain',
        'size_bytes' => 7,
        'local_path' => $pendingPath,
        'server_path' => null,
        'container_path' => null,
        'status' => TerminalUploadedFileStatus::Pending,
        'uploaded_at' => now()->subHours(4),
        'expires_at' => null,
        'finalized_at' => null,
    ]);

    $this->artisan('cleanup:terminal-uploads', ['--pending-hours' => 1])
        ->expectsOutput('Cleaned 1 expired terminal uploads.')
        ->expectsOutput('Cleaned 1 stale pending terminal uploads.')
        ->assertSuccessful();

    $expiredUpload->refresh();
    $pendingUpload->refresh();

    expect(file_exists($expiredPath))->toBeFalse()
        ->and(file_exists($pendingPath))->toBeFalse()
        ->and($expiredUpload->status)->toBe(TerminalUploadedFileStatus::Deleted)
        ->and($pendingUpload->status)->toBe(TerminalUploadedFileStatus::Deleted)
        ->and($expiredUpload->deleted_at)->not->toBeNull()
        ->and($pendingUpload->deleted_at)->not->toBeNull();
});
