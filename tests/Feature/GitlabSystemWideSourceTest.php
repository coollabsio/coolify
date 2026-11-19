<?php

use App\Models\GitlabApp;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

test('ownedByCurrentTeam resolves a system-wide GitLab source owned by another team', function () {
    $otherTeam = Team::factory()->create();

    $systemWide = GitlabApp::create([
        'name' => 'Shared GitLab',
        'api_url' => 'https://gitlab.example.com/api/v4',
        'html_url' => 'https://gitlab.example.com',
        'team_id' => $otherTeam->id,
        'is_system_wide' => true,
        'is_public' => false,
    ]);

    // Mirrors Source::changeSource() resolution; before the fix this returned null for system-wide sources (404).
    $resolved = GitlabApp::ownedByCurrentTeam()->find($systemWide->id);

    expect($resolved)->not->toBeNull();
    expect($resolved->id)->toBe($systemWide->id);
});

test('ownedByCurrentTeam still excludes other teams private (non system-wide) sources', function () {
    $otherTeam = Team::factory()->create();

    $foreign = GitlabApp::create([
        'name' => 'Foreign GitLab',
        'api_url' => 'https://gitlab.example.com/api/v4',
        'html_url' => 'https://gitlab.example.com',
        'team_id' => $otherTeam->id,
        'is_system_wide' => false,
        'is_public' => false,
    ]);

    expect(GitlabApp::ownedByCurrentTeam()->find($foreign->id))->toBeNull();
});
