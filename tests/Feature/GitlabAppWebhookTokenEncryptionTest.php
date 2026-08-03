<?php

use App\Models\GitlabApp;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = Team::create([
        'name' => 'Webhook Token Team',
        'personal_team' => false,
    ]);
});

it('encrypts webhook tokens at rest', function () {
    $app = GitlabApp::create([
        'name' => 'Encrypted webhook',
        'api_url' => 'https://gitlab.com/api/v4',
        'html_url' => 'https://gitlab.com',
        'custom_user' => 'git',
        'custom_port' => 22,
        'webhook_token' => 'plain-webhook-secret',
        'team_id' => $this->team->id,
        'is_system_wide' => false,
        'is_public' => false,
    ]);

    $raw = DB::table('gitlab_apps')->where('id', $app->id)->value('webhook_token');
    expect($raw)->not->toBe('plain-webhook-secret')
        ->and(Crypt::decryptString($raw))->toBe('plain-webhook-secret')
        ->and($app->fresh()->webhook_token)->toBe('plain-webhook-secret');
});

it('finds an app by webhook token for both encrypted and legacy plaintext values', function () {
    $encrypted = GitlabApp::create([
        'name' => 'Encrypted',
        'api_url' => 'https://gitlab.com/api/v4',
        'html_url' => 'https://gitlab.com',
        'custom_user' => 'git',
        'custom_port' => 22,
        'webhook_token' => 'encrypted-secret',
        'team_id' => $this->team->id,
        'is_system_wide' => false,
        'is_public' => false,
    ]);

    $legacy = GitlabApp::create([
        'name' => 'Legacy',
        'api_url' => 'https://gitlab.com/api/v4',
        'html_url' => 'https://gitlab.com',
        'custom_user' => 'git',
        'custom_port' => 22,
        'team_id' => $this->team->id,
        'is_system_wide' => false,
        'is_public' => false,
    ]);
    DB::table('gitlab_apps')->where('id', $legacy->id)->update([
        'webhook_token' => 'legacy-plain-secret',
    ]);

    expect(GitlabApp::findByWebhookToken('encrypted-secret')?->id)->toBe($encrypted->id)
        ->and(GitlabApp::findByWebhookToken('legacy-plain-secret')?->id)->toBe($legacy->id)
        ->and(GitlabApp::findByWebhookToken('missing'))->toBeNull();
});
