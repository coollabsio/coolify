<?php

use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('populates fqdn from docker_compose_domains after generate_preview_fqdn_compose', function () {
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

    $preview = ApplicationPreview::create([
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

it('preserves distinct services whose normalized names collide when generating preview domains', function () {
    $dockerCompose = <<<'YAML'
services:
  api-test:
    image: nginx:alpine
  api.test:
    image: nginx:alpine
YAML;

    $team = Team::factory()->create();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $server = Server::factory()->create(['team_id' => $team->id]);
    $destination = StandaloneDocker::query()->where('server_id', $server->id)->first()
        ?? StandaloneDocker::factory()->create(['server_id' => $server->id]);

    $application = Application::factory()->create([
        'build_pack' => 'dockercompose',
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
        'docker_compose_raw' => $dockerCompose,
        'docker_compose_domains' => json_encode([
            'api-test' => ['domain' => 'https://hyphen.example.com'],
            'api.test' => ['domain' => 'https://dot.example.com'],
        ]),
    ]);

    $preview = ApplicationPreview::create([
        'application_id' => $application->id,
        'pull_request_id' => 17,
        'pull_request_html_url' => 'https://github.com/example/repo/pull/17',
        'docker_compose_domains' => $application->docker_compose_domains,
    ]);

    $preview->generate_preview_fqdn_compose();
    $preview->refresh();

    $domains = json_decode($preview->docker_compose_domains, true);

    expect($domains)->toHaveKeys(['api-test', 'api.test'])
        ->and($domains)->toHaveCount(2)
        ->and($domains['api-test']['domain'])->toContain('hyphen.example.com')
        ->and($domains['api.test']['domain'])->toContain('dot.example.com');
});

it('sets fqdn to null when no domains are configured', function () {
    $application = Application::factory()->create([
        'build_pack' => 'dockercompose',
        'docker_compose_domains' => json_encode([
            'web' => ['domain' => ''],
        ]),
    ]);

    $preview = ApplicationPreview::create([
        'application_id' => $application->id,
        'pull_request_id' => 99,
        'pull_request_html_url' => 'https://github.com/example/repo/pull/99',
        'docker_compose_domains' => $application->docker_compose_domains,
    ]);

    $preview->generate_preview_fqdn_compose();

    $preview->refresh();

    expect($preview->fqdn)->toBeNull();
});

it('collapses dashed and underscore twin domain keys into one preview service', function () {
    $application = Application::factory()->create([
        'build_pack' => 'dockercompose',
        'docker_compose_domains' => json_encode([
            'web-api' => ['domain' => 'https://web-api.example.com'],
            'web_api' => ['domain' => ''],
        ]),
    ]);

    $preview = ApplicationPreview::create([
        'application_id' => $application->id,
        'pull_request_id' => 58,
        'pull_request_html_url' => 'https://github.com/example/repo/pull/58',
        // Existing dual-key preview state from older Coolify versions / mixed write paths.
        'docker_compose_domains' => json_encode([
            'web_api' => ['domain' => 'https://old-preview.example.com'],
            'web-api' => ['domain' => ''],
        ]),
    ]);

    $preview->generate_preview_fqdn_compose();
    $preview->refresh();

    $domains = json_decode($preview->docker_compose_domains, true);

    expect($domains)->toHaveKey('web-api')
        ->and($domains)->not->toHaveKey('web_api')
        ->and($domains)->toHaveCount(1)
        ->and($domains['web-api']['domain'])->toContain('web-api.example.com')
        ->and($domains['web-api']['domain'])->toContain('58')
        ->and($preview->fqdn)->toContain('web-api.example.com');
});

it('reads legacy underscore application domains when generating previews for dashed services', function () {
    $application = Application::factory()->create([
        'build_pack' => 'dockercompose',
        'docker_compose_domains' => json_encode([
            'web_api' => ['domain' => 'https://legacy.example.com'],
        ]),
    ]);

    $preview = ApplicationPreview::create([
        'application_id' => $application->id,
        'pull_request_id' => 12,
        'pull_request_html_url' => 'https://github.com/example/repo/pull/12',
        'docker_compose_domains' => json_encode([
            'web-api' => ['domain' => ''],
        ]),
    ]);

    $preview->generate_preview_fqdn_compose();
    $preview->refresh();

    $domains = json_decode($preview->docker_compose_domains, true);

    expect($domains)->toHaveKey('web-api')
        ->and($domains)->not->toHaveKey('web_api')
        ->and($domains['web-api']['domain'])->toContain('legacy.example.com')
        ->and($preview->fqdn)->toContain('legacy.example.com');
});
