<?php

use App\Models\S3Storage;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Create a user and team
    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->user->teams()->attach($this->team, ['role' => 'owner']);

    // Create S3 storage
    $this->s3Storage = S3Storage::create([
        'uuid' => 'test-s3-uuid-'.uniqid(),
        'team_id' => $this->team->id,
        'name' => 'Test S3',
        'key' => 'test-key',
        'secret' => 'test-secret',
        'region' => 'us-east-1',
        'bucket' => 'test-bucket',
        'endpoint' => 'https://s3.amazonaws.com',
        'is_usable' => true,
    ]);

    // Authenticate as the user
    $this->actingAs($this->user);
    $this->user->currentTeam()->associate($this->team);
    $this->user->save();
});

test('S3Storage can be created with team association', function () {
    expect($this->s3Storage->team_id)->toBe($this->team->id);
    expect($this->s3Storage->name)->toBe('Test S3');
    expect($this->s3Storage->is_usable)->toBeTrue();
});

test('Only usable S3 storages are loaded', function () {
    // Create an unusable S3 storage
    S3Storage::create([
        'uuid' => 'test-s3-uuid-unusable-'.uniqid(),
        'team_id' => $this->team->id,
        'name' => 'Unusable S3',
        'key' => 'key',
        'secret' => 'secret',
        'region' => 'us-east-1',
        'bucket' => 'bucket',
        'endpoint' => 'https://s3.amazonaws.com',
        'is_usable' => false,
    ]);

    // Query only usable S3 storages
    $usableS3Storages = S3Storage::where('team_id', $this->team->id)
        ->where('is_usable', true)
        ->get();

    expect($usableS3Storages)->toHaveCount(1);
    expect($usableS3Storages->first()->name)->toBe('Test S3');
});

test('S3 storages are isolated by team', function () {
    // Create another team with its own S3 storage
    $otherTeam = Team::factory()->create();
    S3Storage::create([
        'uuid' => 'test-s3-uuid-other-'.uniqid(),
        'team_id' => $otherTeam->id,
        'name' => 'Other Team S3',
        'key' => 'key',
        'secret' => 'secret',
        'region' => 'us-east-1',
        'bucket' => 'bucket',
        'endpoint' => 'https://s3.amazonaws.com',
        'is_usable' => true,
    ]);

    // Current user's team should only see their S3
    $teamS3Storages = S3Storage::where('team_id', $this->team->id)
        ->where('is_usable', true)
        ->get();

    expect($teamS3Storages)->toHaveCount(1);
    expect($teamS3Storages->first()->name)->toBe('Test S3');
});

test('S3Storage model has required fields', function () {
    expect($this->s3Storage)->toHaveProperty('key');
    expect($this->s3Storage)->toHaveProperty('secret');
    expect($this->s3Storage)->toHaveProperty('bucket');
    expect($this->s3Storage)->toHaveProperty('endpoint');
    expect($this->s3Storage)->toHaveProperty('region');
});
