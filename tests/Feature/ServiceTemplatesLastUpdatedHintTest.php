<?php

use App\Livewire\Project\New\Select;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ViewErrorBag;

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

it('crops wide database wordmarks to their icon artwork', function () {
    $databases = collect((new Select)->loadServices()['databases'])->keyBy('id');

    expect($databases['keydb']['logo'])->toContain('viewBox="0 0 160 182"')
        ->and($databases['dragonfly']['logo'])->toContain('viewBox="0 0 44 44"', 'viewBox="0 0 88 88"')
        ->and($databases['clickhouse']['logo'])->toContain('viewBox="0 0 24 26"');
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

it('renders the shared loading indicator while resource choices load', function () {
    View::share('errors', new ViewErrorBag);

    $view = $this->view('livewire.project.new.select', [
        'current_step' => 'type',
        'environments' => collect(),
    ]);

    $view->assertSee('Loading resources...', false);
    $view->assertSee('animate-spin', false);
    $view->assertDontSee('<div x-show="loading">Loading...</div>', false);
});

it('renders the service templates last updated hint placeholder', function () {
    View::share('errors', new ViewErrorBag);

    $view = $this->view('livewire.project.new.select', [
        'current_step' => 'type',
        'environments' => collect(),
    ]);

    $view->assertSee('Updated');
    $view->assertSee('serviceTemplatesLastUpdated');
    $view->assertSee('service.templateLastUpdated');
    $view->assertSee('aria-controls="resource-type-filter-options"', false);
    $view->assertSee('aria-controls="resource-category-options"', false);
    $view->assertSee('@click.outside="closeCategoryFilter()"', false);
    $view->assertSee('@keydown.escape.stop="closeCategoryFilter(true)"', false);
});

it('renders the local, CDN, and default service logo fallback chain', function () {
    View::share('errors', new ViewErrorBag);

    $view = $this->view('livewire.project.new.select', [
        'current_step' => 'type',
        'environments' => collect(),
    ]);

    $view->assertSee('service.logo_cdn_url', false);
    $view->assertSee('service.logo_default_url', false);

    expect(file_get_contents(resource_path('views/livewire/global-search.blade.php')))
        ->toContain('item.logo_cdn_url')
        ->toContain('item.logo_default_url');
});

it('keeps service template keys for service selection and docs links', function () {
    $services = collect((new Select)->loadServices()['services']);
    $denoKv = $services->firstWhere('id', 'denoKV');

    expect($denoKv)
        ->not->toBeNull()
        ->and($denoKv['docsSlug'])->toBe('denokv');

    View::share('errors', new ViewErrorBag);

    $view = $this->view('livewire.project.new.select', [
        'current_step' => 'type',
        'environments' => collect(),
    ]);

    $view->assertSee("setType('one-click-service-' + service.id)", false);
    $view->assertSee('service.docsSlug || this.extractBaseServiceName(service.name)', false);
});

it('preserves one click service key casing when selecting a service template', function () {
    $component = new Select;
    $component->servers = collect();
    $component->allServers = collect();

    $component->setType('one-click-service-denoKV');

    expect($component->type)->toBe('one-click-service-denoKV');
});
