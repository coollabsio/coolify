<?php

use App\Enums\TerminalUploadedFileStatus;
use App\Models\Team;
use App\Models\TerminalUploadedFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = Team::factory()->create();
});

it('denies unauthenticated terminal uploads', function () {
    $this->postJson(route('upload.terminal'))
        ->assertStatus(401);
});

it('denies terminal uploads for team members without terminal access', function () {
    $member = User::factory()->create();
    $member->teams()->attach($this->team, ['role' => 'member']);

    $this->actingAs($member);
    session(['currentTeam' => $this->team]);

    $this->post(route('upload.terminal'), [
        'file' => UploadedFile::fake()->create('notes.txt', 1),
    ], [
        'Accept' => 'application/json',
    ])->assertForbidden();
});

it('stores a pending terminal upload record for team owners', function () {
    $owner = User::factory()->create();
    $owner->teams()->attach($this->team, ['role' => 'owner']);

    $this->actingAs($owner);
    session(['currentTeam' => $this->team]);

    $file = UploadedFile::fake()->create('terminal-import.sql', 32);

    $response = $this->post(route('upload.terminal'), [
        'file' => $file,
        'resumableChunkNumber' => 1,
        'resumableTotalChunks' => 1,
        'resumableChunkSize' => $file->getSize(),
        'resumableCurrentChunkSize' => $file->getSize(),
        'resumableTotalSize' => $file->getSize(),
        'resumableType' => $file->getMimeType(),
        'resumableIdentifier' => 'terminal_upload_token',
        'resumableFilename' => 'terminal-import.sql',
        'resumableRelativePath' => 'terminal-import.sql',
    ], [
        'Accept' => 'application/json',
    ]);

    $response
        ->assertOk()
        ->assertJson([
            'done' => 100,
            'status' => true,
            'upload_token' => 'terminal_upload_token',
            'original_name' => 'terminal-import.sql',
        ]);

    $storedFilename = $response->json('stored_filename');
    $fileUuid = $response->json('file_uuid');

    expect($storedFilename)
        ->not->toBeEmpty()
        ->and($storedFilename)->toEndWith('.sql');

    $terminalUploadedFile = TerminalUploadedFile::query()->where('uuid', $fileUuid)->first();

    expect($terminalUploadedFile)
        ->not->toBeNull()
        ->and($terminalUploadedFile->user_id)->toBe($owner->id)
        ->and($terminalUploadedFile->team_id)->toBe($this->team->id)
        ->and($terminalUploadedFile->upload_token)->toBe('terminal_upload_token')
        ->and($terminalUploadedFile->status)->toBe(TerminalUploadedFileStatus::Pending)
        ->and($terminalUploadedFile->original_name)->toBe('terminal-import.sql')
        ->and(file_exists($terminalUploadedFile->local_path))->toBeTrue();
});
