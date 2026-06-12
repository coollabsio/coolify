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

it('prefers embedded service template git timestamps from the templates bundle', function () {
    File::shouldReceive('get')
        ->with(base_path('templates/'.config('constants.services.file_name')))
        ->andReturn(json_encode([
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
        ]));

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
