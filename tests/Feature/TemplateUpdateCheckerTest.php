<?php

use App\Models\Service;
use App\Services\TemplateFingerprint;
use App\Services\TemplateUpdateChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

function fakeTemplateBundle(string $slug, string $compose): void
{
    Cache::flush();
    $json = json_encode([$slug => ['compose' => base64_encode($compose)]]);
    Cache::forever('coolify:service-templates-bundle', ['fetched_at' => now()->toIso8601String(), 'json' => $json]);
}

it('reports no update when the reference hash matches the current template', function () {
    fakeTemplateBundle('demo', "services:\n  app:\n    image: nginx:1\n");
    $current = TemplateUpdateChecker::currentHash('demo');
    $service = new Service(['service_type' => 'demo', 'template_reference_hash' => $current]);

    expect(TemplateUpdateChecker::updateAvailable($service))->toBeFalse();
});

it('reports an update when the template moved past the reference hash', function () {
    fakeTemplateBundle('demo', "services:\n  app:\n    image: nginx:2\n");
    $service = new Service(['service_type' => 'demo', 'template_reference_hash' => 'stale-hash']);

    expect(TemplateUpdateChecker::updateAvailable($service))->toBeTrue();
});

it('suppresses the badge when the current version was dismissed', function () {
    fakeTemplateBundle('demo', "services:\n  app:\n    image: nginx:2\n");
    $current = TemplateUpdateChecker::currentHash('demo');
    $service = new Service([
        'service_type' => 'demo',
        'template_reference_hash' => 'stale-hash',
        'template_dismissed_hash' => $current,
    ]);

    expect(TemplateUpdateChecker::updateAvailable($service))->toBeTrue();
    expect(TemplateUpdateChecker::showBadge($service))->toBeFalse();
});

it('produces a reference hash that matches the checker for the same template', function () {
    // Locks the producer (Create sets template_reference_hash via TemplateFingerprint::forTemplate)
    // to the consumer (TemplateUpdateChecker::currentHash), so a new service is never born stale.
    fakeTemplateBundle('demo', "services:\n  app:\n    image: nginx:7\n");

    $template = data_get(get_service_templates(), 'demo');

    expect(TemplateFingerprint::forTemplate($template))->toBe(TemplateUpdateChecker::currentHash('demo'));
});

it('never flags a service whose service_type maps to no template', function () {
    fakeTemplateBundle('demo', "services:\n  app:\n    image: nginx:2\n");
    $service = new Service(['service_type' => 'unknown-slug', 'template_reference_hash' => 'x']);

    expect(TemplateUpdateChecker::currentHash('unknown-slug'))->toBeNull();
    expect(TemplateUpdateChecker::updateAvailable($service))->toBeFalse();
    expect(TemplateUpdateChecker::showBadge($service))->toBeFalse();
});
