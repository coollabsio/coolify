<?php

use App\Ai\Agents\CoolifyAssistant;
use App\Ai\Tools\ListGithubApps;
use App\Ai\Tools\ListPrivateKeys;
use App\Models\GithubApp;
use App\Models\PrivateKey;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Tools\Request;

uses(RefreshDatabase::class);

function aiKeysRsaPem(): string
{
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);

    return $pem;
}

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->user->teams()->attach($this->team, ['role' => 'member']);
    $this->actingAs($this->user);
    session(['currentTeam' => ['id' => $this->team->id]]);
});

test('ListPrivateKeys lists team keys by uuid without key material', function () {
    $key = PrivateKey::factory()->create(['team_id' => $this->team->id, 'name' => 'gitlab-deploy', 'is_git_related' => true]);
    PrivateKey::factory()->create(['team_id' => Team::factory()->create()->id, 'name' => 'foreign', 'private_key' => aiKeysRsaPem()]);

    $out = (string) (new ListPrivateKeys)->handle(new Request([]));

    expect($out)->toContain($key->uuid)->toContain('gitlab-deploy')->toContain('(git)')
        ->and($out)->not->toContain('foreign')->not->toContain('BEGIN OPENSSH');
});

test('ListGithubApps lists team-owned and system-wide apps', function () {
    $mine = GithubApp::unguarded(fn () => GithubApp::create(['name' => 'mine', 'api_url' => 'https://api.github.com', 'html_url' => 'https://github.com', 'team_id' => $this->team->id]));
    $wide = GithubApp::unguarded(fn () => GithubApp::create(['name' => 'wide', 'api_url' => 'https://api.github.com', 'html_url' => 'https://github.com', 'team_id' => Team::factory()->create()->id, 'is_system_wide' => true]));
    GithubApp::unguarded(fn () => GithubApp::create(['name' => 'foreign', 'api_url' => 'https://api.github.com', 'html_url' => 'https://github.com', 'team_id' => Team::factory()->create()->id]));

    $out = (string) (new ListGithubApps)->handle(new Request([]));

    expect($out)->toContain($mine->uuid)->toContain($wide->uuid)->not->toContain('foreign');
});

test('the assistant registers both discovery tools', function () {
    $classes = array_map(fn ($t) => $t::class, (new CoolifyAssistant)->tools());

    expect($classes)->toContain(ListPrivateKeys::class)->toContain(ListGithubApps::class);
});
