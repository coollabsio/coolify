<?php

use App\Models\Application;
use App\Models\ApplicationPreview;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makePreviewForApplication(array $applicationAttributes, int $pullRequestId = 42): ApplicationPreview
{
    $application = Application::factory()->create($applicationAttributes);

    return ApplicationPreview::create([
        'application_id' => $application->id,
        'pull_request_id' => $pullRequestId,
        'pull_request_html_url' => "https://github.com/example/repo/pull/{$pullRequestId}",
    ]);
}

it('generates the preview fqdn from the application domain', function () {
    $preview = makePreviewForApplication([
        'fqdn' => 'https://example.com',
        'preview_url_template' => '{{pr_id}}.{{domain}}',
    ]);

    $preview->generate_preview_fqdn();

    expect($preview->refresh()->fqdn)->toBe('https://42.example.com');
});

it('generates one preview fqdn per application domain', function () {
    $preview = makePreviewForApplication([
        'fqdn' => 'https://app.example.com,https://api.example.com',
        'preview_url_template' => '{{pr_id}}.{{domain}}',
    ]);

    $preview->generate_preview_fqdn();

    expect($preview->refresh()->fqdn)->toBe('https://42.app.example.com,https://42.api.example.com');
});

it('preserves scheme and path per domain', function () {
    $preview = makePreviewForApplication([
        'fqdn' => 'http://app.example.com/api,https://api.example.com',
        'preview_url_template' => '{{pr_id}}.{{domain}}',
    ]);

    $preview->generate_preview_fqdn();

    expect($preview->refresh()->fqdn)->toBe('http://42.app.example.com/api,https://42.api.example.com');
});

it('records a port override per generated domain', function () {
    $preview = makePreviewForApplication([
        'fqdn' => 'http://app.example.com:3000,https://api.example.com',
        'preview_url_template' => '{{pr_id}}.{{domain}}',
    ]);

    $preview->generate_preview_fqdn();
    $preview->refresh();

    expect($preview->fqdn)->toBe('http://42.app.example.com,https://42.api.example.com')
        ->and($preview->domain_port_overrides)->toBe(['http://42.app.example.com' => 3000]);
});

it('deduplicates preview fqdns when the template drops the source domain', function () {
    $preview = makePreviewForApplication([
        'fqdn' => 'https://app.example.com,https://api.example.com',
        'preview_url_template' => '{{pr_id}}.preview.example.com',
    ]);

    $preview->generate_preview_fqdn();

    expect($preview->refresh()->fqdn)->toBe('https://42.preview.example.com');
});

it('keeps domains added to the preview when a deployment regenerates', function () {
    $preview = makePreviewForApplication([
        'fqdn' => 'https://example.com',
        'preview_url_template' => '{{pr_id}}.{{domain}}',
    ]);

    $preview->fqdn = 'https://first.example.com,https://second.example.com';
    $preview->save();

    $preview->generate_preview_fqdn();

    expect($preview->refresh()->fqdn)->toBe('https://first.example.com,https://second.example.com');
});

it('overwrites the preview fqdn when regeneration is forced', function () {
    $preview = makePreviewForApplication([
        'fqdn' => 'https://example.com',
        'preview_url_template' => '{{pr_id}}.{{domain}}',
    ]);

    $preview->fqdn = 'https://custom.example.com';
    $preview->save();

    $preview->generate_preview_fqdn(force: true);

    expect($preview->refresh()->fqdn)->toBe('https://42.example.com');
});
