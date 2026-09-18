<?php

use App\Models\Application;
use App\Models\GithubApp;
use App\Models\GitlabApp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    DB::table('instance_settings')->insert([
        'id' => 0,
        'fqdn' => 'https://coolify.example.com',
    ]);
});

it('hides manual webhooks for github app sources (app-level webhook registration)', function () {
    $application = new Application(['source_id' => 1, 'source_type' => GithubApp::class]);

    expect(generateGitManualWebhook($application, 'github'))->toBeNull()
        ->and(generateGitManualWebhook($application, 'gitlab'))->toBeNull();
});

it('shows manual webhooks for gitlab app sources (no app-level webhook registration)', function () {
    $application = new Application(['source_id' => 1, 'source_type' => GitlabApp::class]);

    expect(generateGitManualWebhook($application, 'gitlab'))
        ->toBe('https://coolify.example.com/webhooks/source/gitlab/events/manual');
});

it('keeps showing manual webhooks when no git app source is connected', function () {
    $application = new Application(['source_id' => null, 'source_type' => null]);

    expect(generateGitManualWebhook($application, 'gitlab'))
        ->toBe('https://coolify.example.com/webhooks/source/gitlab/events/manual');
});
