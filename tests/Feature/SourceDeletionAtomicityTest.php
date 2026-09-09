<?php

use App\Models\GithubApp;
use App\Models\PrivateKey;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function sourcePrivateKey(Team $team): PrivateKey
{
    return PrivateKey::factory()->create([
        'team_id' => $team->id,
        'is_git_related' => true,
    ]);
}

test('github source deletion rolls back unused private key deletion when source deletion fails', function () {
    $team = Team::factory()->create();
    $privateKey = sourcePrivateKey($team);
    $githubApp = GithubApp::create([
        'name' => 'GitHub',
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'private_key_id' => $privateKey->id,
        'team_id' => $team->id,
    ]);

    GithubApp::deleting(function (GithubApp $deletingApp) use ($githubApp): void {
        if ($deletingApp->is($githubApp)) {
            throw new RuntimeException('Source deletion failed.');
        }
    });

    expect(fn () => $githubApp->delete())->toThrow(RuntimeException::class, 'Source deletion failed.');

    $this->assertModelExists($githubApp);
    $this->assertModelExists($privateKey);
});
