<?php

use App\Models\Application;
use App\Models\ApplicationPreview;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('preserves custom preview domain when regeneration is not forced', function () {
    $application = Application::factory()->create([
        'fqdn' => 'https://example.com',
        'preview_url_template' => '{{pr_id}}.{{domain}}',
    ]);

    $preview = ApplicationPreview::create([
        'application_id' => $application->id,
        'pull_request_id' => 42,
        'pull_request_html_url' => 'https://github.com/example/repo/pull/42',
        'fqdn' => 'https://custom-preview-domain.com',
    ]);

    $preview->generate_preview_fqdn(force: false);
    $preview->refresh();

    expect($preview->fqdn)->toBe('https://custom-preview-domain.com');
});

it('regenerates preview domain from template when forced', function () {
    $application = Application::factory()->create([
        'fqdn' => 'https://example.com',
        'preview_url_template' => '{{pr_id}}.{{domain}}',
    ]);

    $preview = ApplicationPreview::create([
        'application_id' => $application->id,
        'pull_request_id' => 42,
        'pull_request_html_url' => 'https://github.com/example/repo/pull/42',
        'fqdn' => 'https://custom-preview-domain.com',
    ]);

    $preview->generate_preview_fqdn(force: true);
    $preview->refresh();

    expect($preview->fqdn)->not->toBe('https://custom-preview-domain.com');
    expect($preview->fqdn)->toContain('42');
    expect($preview->fqdn)->toContain('example.com');
});

it('generates preview domain from template when none exists and not forced', function () {
    $application = Application::factory()->create([
        'fqdn' => 'https://example.com',
        'preview_url_template' => '{{pr_id}}.{{domain}}',
    ]);

    $preview = ApplicationPreview::create([
        'application_id' => $application->id,
        'pull_request_id' => 42,
        'pull_request_html_url' => 'https://github.com/example/repo/pull/42',
    ]);

    $preview->generate_preview_fqdn(force: false);
    $preview->refresh();

    expect($preview->fqdn)->not->toBeNull();
    expect($preview->fqdn)->toContain('42');
    expect($preview->fqdn)->toContain('example.com');
});

it('preserves custom docker compose preview domains when regeneration is not forced', function () {
    $application = Application::factory()->create([
        'build_pack' => 'dockercompose',
        'docker_compose_domains' => json_encode([
            'web' => ['domain' => 'https://example.com'],
        ]),
    ]);

    $preview = ApplicationPreview::create([
        'application_id' => $application->id,
        'pull_request_id' => 42,
        'pull_request_html_url' => 'https://github.com/example/repo/pull/42',
        'docker_compose_domains' => json_encode([
            'web' => ['domain' => 'https://custom-preview-domain.com'],
        ]),
    ]);

    $preview->generate_preview_fqdn_compose(force: false);
    $preview->refresh();

    expect($preview->docker_compose_domains)->toContain('custom-preview-domain.com');
    expect($preview->docker_compose_domains)->not->toContain('42.example.com');
});

it('regenerates docker compose preview domains from template when forced', function () {
    $application = Application::factory()->create([
        'build_pack' => 'dockercompose',
        'docker_compose_domains' => json_encode([
            'web' => ['domain' => 'https://example.com'],
        ]),
    ]);

    $preview = ApplicationPreview::create([
        'application_id' => $application->id,
        'pull_request_id' => 42,
        'pull_request_html_url' => 'https://github.com/example/repo/pull/42',
        'docker_compose_domains' => json_encode([
            'web' => ['domain' => 'https://custom-preview-domain.com'],
        ]),
    ]);

    $preview->generate_preview_fqdn_compose(force: true);
    $preview->refresh();

    expect($preview->docker_compose_domains)->not->toContain('custom-preview-domain.com');
    expect($preview->docker_compose_domains)->toContain('42.example.com');
});

it('generates multiple docker compose preview domains when main app has multiple domains', function () {
    $application = Application::factory()->create([
        'build_pack' => 'dockercompose',
        'preview_url_template' => '{{pr_id}}.{{domain}}',
        'docker_compose_domains' => json_encode([
            'web' => ['domain' => 'https://example.com,https://www.example.com'],
        ]),
    ]);

    $preview = ApplicationPreview::create([
        'application_id' => $application->id,
        'pull_request_id' => 42,
        'pull_request_html_url' => 'https://github.com/example/repo/pull/42',
    ]);

    $preview->generate_preview_fqdn_compose(force: true);
    $preview->refresh();

    expect($preview->docker_compose_domains)->toContain('42.example.com');
    expect($preview->docker_compose_domains)->toContain('42.www.example.com');
});
