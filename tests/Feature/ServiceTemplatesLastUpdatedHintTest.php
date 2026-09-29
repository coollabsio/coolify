<?php

use App\Livewire\Project\New\Select;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    Cache::flush();
});

it('returns the service templates bundle last updated timestamp', function () {
    $component = new Select;
    $templatePath = base_path('templates/'.config('constants.services.file_name'));

    $resources = $component->loadServices();

    expect($resources)
        ->toHaveKey('serviceTemplatesLastUpdated')
        ->and($resources['serviceTemplatesLastUpdated'])
        ->toBe(CarbonImmutable::createFromTimestamp(filemtime($templatePath))->timezone(config('app.timezone'))->format('M j, Y H:i'));
});

it('returns each service template last updated timestamp from the generated bundle', function () {
    $component = new Select;
    $templates = json_decode(file_get_contents(base_path('templates/'.config('constants.services.file_name'))), true);
    $templateTimestamp = $templates['activepieces']['template_last_updated_at'];

    $resources = $component->loadServices();

    expect($resources['services']['activepieces'])
        ->toHaveKey('templateLastUpdated')
        ->and($resources['services']['activepieces']['templateLastUpdated'])
        ->toBe(CarbonImmutable::parse($templateTimestamp)->timezone(config('app.timezone'))->format('M j, Y H:i'));
});

it('returns local, CDN, and default logo fallbacks for every service', function () {
    $services = (new Select)->loadServices()['services'];

    expect($services['opnform']['logo'])->toBe(asset('svgs/opnform.svg'))
        ->and($services['pydio-cells']['logo'])->toBe(asset('svgs/cells.svg'))
        ->and($services['pydio-cells']['logo_cdn_url'])
        ->toBe('https://raw.githubusercontent.com/coollabsio/coolify/refs/heads/main/public/svgs/cells.svg')
        ->and($services['pydio-cells']['logo_default_url'])->toBe(asset('svgs/default.webp'));
});

it('uses resource tile icons for databases', function () {
    $databases = collect((new Select)->loadServices()['databases'])->keyBy('id');

    foreach (['keydb', 'dragonfly', 'clickhouse'] as $database) {
        expect($databases[$database]['logo'])->toBe(asset("svgs/resources/{$database}.svg"));
    }
});

it('prefers embedded service template git timestamps from the templates bundle', function () {
    $path = base_path('templates/'.config('constants.services.file_name'));
    $payload = json_encode([
        'activepieces' => [
            'documentation' => 'https://coolify.io/docs',
            'slogan' => 'Open source no-code business automation.',
            'compose' => '',
            'tags' => null,
            'category' => 'automation',
            'logo' => 'images/default.webp',
            'minversion' => '0.0.0',
            'template_last_updated_at' => '2026-05-31T12:34:56+00:00',
        ],
    ]);

    File::partialMock()
        ->shouldReceive('exists')
        ->with($path)
        ->andReturn(true)
        ->shouldReceive('get')
        ->with($path)
        ->andReturn($payload);

    $resources = (new Select)->loadServices();

    expect($resources['services']['activepieces']['templateLastUpdated'])->toBe('May 31, 2026 12:34');
});

it('caches parsed local service templates by bundle mtime', function () {
    Cache::flush();

    $path = base_path('templates/'.config('constants.services.file_name'));
    $json = file_get_contents($path);

    File::partialMock()
        ->shouldReceive('get')
        ->once()
        ->with($path)
        ->andReturn($json);

    $first = get_service_templates();
    $second = get_service_templates();

    expect($first->keys()->all())->toBe($second->keys()->all());
});

it('keeps service template keys for service selection and docs links', function () {
    $services = collect((new Select)->loadServices()['services']);
    $denoKv = $services->firstWhere('id', 'denoKV');

    expect($denoKv)
        ->not->toBeNull()
        ->and($denoKv['docsSlug'])->toBe('denokv');
});

it('preserves one click service key casing when selecting a service template', function () {
    $component = new Select;
    $component->servers = collect();
    $component->allServers = collect();

    $component->setType('one-click-service-denoKV');

    expect($component->type)->toBe('one-click-service-denoKV');
});
