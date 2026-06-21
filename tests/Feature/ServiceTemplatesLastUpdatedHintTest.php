<?php

use App\Livewire\Project\New\Select;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
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

it('returns each service template last updated timestamp', function () {
    $component = new Select;
    $templatePath = base_path('templates/compose/activepieces.yaml');

    $resources = $component->loadServices();

    expect($resources['services']['activepieces'])
        ->toHaveKey('templateLastUpdated')
        ->and($resources['services']['activepieces']['templateLastUpdated'])
        ->toBe(CarbonImmutable::createFromTimestamp(filemtime($templatePath))->timezone(config('app.timezone'))->format('M j, Y H:i'));
});

it('uses a service template timestamp cache keyed by bundle mtime', function () {
    $bundleMtime = filemtime(base_path('templates/'.config('constants.services.file_name')));
    Cache::put("service-template-last-updated-map:{$bundleMtime}", [
        'activepieces' => 'Cached timestamp',
    ], now()->addDay());

    $resources = (new Select)->loadServices();

    expect($resources['services']['activepieces']['templateLastUpdated'])->toBe('Cached timestamp');
});

it('does not use stale service template timestamp cache entries from another bundle mtime', function () {
    $bundleMtime = filemtime(base_path('templates/'.config('constants.services.file_name')));
    Cache::put('service-template-last-updated-map:'.($bundleMtime - 1), [
        'activepieces' => 'Stale cached timestamp',
    ], now()->addDay());

    $resources = (new Select)->loadServices();

    expect($resources['services']['activepieces']['templateLastUpdated'])->not->toBe('Stale cached timestamp');
});

it('renders the service templates last updated hint placeholder', function () {
    View::share('errors', new ViewErrorBag);

    $view = $this->view('livewire.project.new.select', [
        'current_step' => 'type',
        'environments' => collect(),
    ]);

    $view->assertSee('Last Updated on Service Templates:');
    $view->assertSee('serviceTemplatesLastUpdated');
    $view->assertSee('service.templateLastUpdated');
});
