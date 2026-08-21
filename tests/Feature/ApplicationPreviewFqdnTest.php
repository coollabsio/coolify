<?php

use App\Jobs\ApplicationDeploymentJob;
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

it('preserves scheme, port and path per domain', function () {
    $preview = makePreviewForApplication([
        'fqdn' => 'http://app.example.com:3000/api,https://api.example.com',
        'preview_url_template' => '{{pr_id}}.{{domain}}',
    ]);

    $preview->generate_preview_fqdn();

    expect($preview->refresh()->fqdn)->toBe('http://42.app.example.com:3000/api,https://42.api.example.com');
});

it('deduplicates preview fqdns generated from a template without placeholders per domain', function () {
    $preview = makePreviewForApplication([
        'fqdn' => 'https://app.example.com,https://api.example.com',
        'preview_url_template' => '{{pr_id}}.preview.example.com',
    ]);

    $preview->generate_preview_fqdn();

    expect($preview->refresh()->fqdn)->toBe('https://42.preview.example.com');
});

it('keeps a customized preview fqdn on regeneration', function () {
    $preview = makePreviewForApplication([
        'fqdn' => 'https://example.com',
        'preview_url_template' => '{{pr_id}}.{{domain}}',
    ]);

    $preview->fqdn = 'https://custom.example.com';
    $preview->save();

    $preview->generate_preview_fqdn();

    expect($preview->refresh()->fqdn)->toBe('https://custom.example.com');
});

it('overwrites a customized preview fqdn when forced', function () {
    $preview = makePreviewForApplication([
        'fqdn' => 'https://example.com',
        'preview_url_template' => '{{pr_id}}.{{domain}}',
    ]);

    $preview->fqdn = 'https://custom.example.com';
    $preview->save();

    $preview->generate_preview_fqdn(force: true);

    expect($preview->refresh()->fqdn)->toBe('https://42.example.com');
});

function coolifyVariablesForPreview(ApplicationPreview $preview): string
{
    $reflection = new ReflectionClass(ApplicationDeploymentJob::class);
    $job = $reflection->newInstanceWithoutConstructor();

    foreach ([
        'application' => $preview->application,
        'preview' => $preview,
        'pull_request_id' => $preview->pull_request_id,
        'commit' => 'HEAD',
    ] as $property => $value) {
        $reflectionProperty = $reflection->getProperty($property);
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue($job, $value);
    }

    $method = $reflection->getMethod('set_coolify_variables');
    $method->setAccessible(true);
    $method->invoke($job);

    $variables = $reflection->getProperty('coolify_variables');
    $variables->setAccessible(true);

    return $variables->getValue($job);
}

it('sets COOLIFY_URL and COOLIFY_FQDN per domain for multi-domain previews', function () {
    $preview = makePreviewForApplication([
        'fqdn' => 'https://app.example.com,https://api.example.com',
        'preview_url_template' => '{{pr_id}}.{{domain}}',
        'compose_parsing_version' => '3',
    ]);

    $preview->generate_preview_fqdn();

    $variables = coolifyVariablesForPreview($preview->refresh());

    expect($variables)
        ->toContain("COOLIFY_URL='https://42.app.example.com,https://42.api.example.com'")
        ->toContain("COOLIFY_FQDN='42.app.example.com,42.api.example.com'");
});

it('keeps single-domain COOLIFY_URL and COOLIFY_FQDN unchanged', function () {
    $preview = makePreviewForApplication([
        'fqdn' => 'https://example.com',
        'preview_url_template' => '{{pr_id}}.{{domain}}',
        'compose_parsing_version' => '3',
    ]);

    $preview->generate_preview_fqdn();

    $variables = coolifyVariablesForPreview($preview->refresh());

    expect($variables)
        ->toContain("COOLIFY_URL='https://42.example.com'")
        ->toContain("COOLIFY_FQDN='42.example.com'");
});
