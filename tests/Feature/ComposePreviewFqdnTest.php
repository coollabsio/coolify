<?php

use App\Models\Application;
use App\Models\ApplicationPreview;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('populates fqdn from docker_compose_domains after generate_preview_fqdn_compose', function () {
    $application = Application::factory()->create([
        'build_pack' => 'dockercompose',
        'docker_compose_domains' => json_encode([
            'web' => ['domain' => 'https://example.com'],
        ]),
    ]);

    $preview = ApplicationPreview::forceCreate([
        'application_id' => $application->id,
        'pull_request_id' => 42,
        'pull_request_html_url' => 'https://github.com/example/repo/pull/42',
        'docker_compose_domains' => $application->docker_compose_domains,
    ]);

    $preview->generate_preview_fqdn_compose();

    $preview->refresh();

    expect($preview->fqdn)->not->toBeNull();
    expect($preview->fqdn)->toContain('42');
    expect($preview->fqdn)->toContain('example.com');
});

it('populates fqdn with multiple domains from multiple services', function () {
    $application = Application::factory()->create([
        'build_pack' => 'dockercompose',
        'docker_compose_domains' => json_encode([
            'web' => ['domain' => 'https://web.example.com'],
            'api' => ['domain' => 'https://api.example.com'],
        ]),
    ]);

    $preview = ApplicationPreview::forceCreate([
        'application_id' => $application->id,
        'pull_request_id' => 7,
        'pull_request_html_url' => 'https://github.com/example/repo/pull/7',
        'docker_compose_domains' => $application->docker_compose_domains,
    ]);

    $preview->generate_preview_fqdn_compose();

    $preview->refresh();

    expect($preview->fqdn)->not->toBeNull();
    $domains = explode(',', $preview->fqdn);
    expect($domains)->toHaveCount(2);
    expect($preview->fqdn)->toContain('web.example.com');
    expect($preview->fqdn)->toContain('api.example.com');
});

it('sets fqdn to null when no domains are configured', function () {
    $application = Application::factory()->create([
        'build_pack' => 'dockercompose',
        'docker_compose_domains' => json_encode([
            'web' => ['domain' => ''],
        ]),
    ]);

    $preview = ApplicationPreview::forceCreate([
        'application_id' => $application->id,
        'pull_request_id' => 99,
        'pull_request_html_url' => 'https://github.com/example/repo/pull/99',
        'docker_compose_domains' => $application->docker_compose_domains,
    ]);

    $preview->generate_preview_fqdn_compose();

    $preview->refresh();

    expect($preview->fqdn)->toBeNull();
});
