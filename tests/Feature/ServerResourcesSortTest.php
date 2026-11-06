<?php

use App\Livewire\Server\Resources;
use App\Models\Server;

function sortableManagedResource(string $name, string $project, string $environment, string $type, string $status): object
{
    return new class($name, $project, $environment, $type, $status)
    {
        public array $environment;

        public function __construct(public string $name, public string $projectName, string $environment, public string $resourceType, public string $status)
        {
            $this->environment = ['name' => $environment];
        }

        public function project(): array
        {
            return ['name' => $this->projectName];
        }

        public function type(): string
        {
            return $this->resourceType;
        }
    };
}

beforeEach(function () {
    $this->component = new Resources;
    $this->component->server = Mockery::mock(Server::class)->makePartial();
});

function sortedNames(Resources $component, string $nameKey = 'name'): array
{
    return $component->render()->getData()['resources']->pluck($nameKey)->all();
}

it('sorts managed resources by each column in both directions with name as tie-breaker', function () {
    $this->component->server->shouldReceive('definedResources')->andReturn(collect([
        sortableManagedResource('web 10', 'Beta', 'production', 'application', 'running:healthy'),
        sortableManagedResource('Web 2', 'alpha', 'staging', 'service', 'exited:unhealthy'),
        sortableManagedResource('db', 'Beta', 'dev', 'standalone-postgresql', 'running:healthy'),
    ]));

    expect(sortedNames($this->component))->toBe(['db', 'Web 2', 'web 10']);

    $this->component->setSort('name_desc');
    expect(sortedNames($this->component))->toBe(['web 10', 'Web 2', 'db']);

    $this->component->setSort('project_asc');
    expect(sortedNames($this->component))->toBe(['Web 2', 'db', 'web 10']);

    $this->component->setSort('environment_desc');
    expect(sortedNames($this->component))->toBe(['Web 2', 'web 10', 'db']);

    $this->component->setSort('type_desc');
    expect(sortedNames($this->component))->toBe(['db', 'Web 2', 'web 10']);

    $this->component->setSort('status_desc');
    expect(sortedNames($this->component))->toBe(['db', 'web 10', 'Web 2']);
});

it('sorts unmanaged containers by image and status', function () {
    $this->component->activeTab = 'unmanaged';
    $this->component->unmanagedContainers = [
        ['Names' => 'cache', 'Image' => 'redis:7', 'State' => 'running'],
        ['Names' => 'proxy', 'Image' => 'nginx:1', 'State' => 'exited'],
        ['Names' => 'app', 'Image' => 'redis:7', 'State' => 'running'],
    ];

    $this->component->setSort('image_asc');
    expect(sortedNames($this->component, 'Names'))->toBe(['proxy', 'app', 'cache']);

    $this->component->setSort('status_asc');
    expect(sortedNames($this->component, 'Names'))->toBe(['proxy', 'app', 'cache']);
});

it('ignores unknown sort values and resets the page on a valid sort', function () {
    $this->component->setPage(3);
    foreach (['bogus', 'name_sideways', 'image_asc', 'name_asc; drop'] as $invalid) {
        $this->component->setSort($invalid);
    }
    expect($this->component->sort)->toBe('name_asc')
        ->and($this->component->getPage())->toBe(3);

    $this->component->setSort('status_desc');
    expect($this->component->sort)->toBe('status_desc')
        ->and($this->component->getPage())->toBe(1);
});

it('renders livewire sort headers for both tabs', function () {
    $view = file_get_contents(resource_path('views/livewire/server/resources.blade.php'));
    foreach (['name', 'project', 'environment', 'type', 'status', 'image'] as $column) {
        expect($view)->toContain("asc=\"{$column}_asc\" desc=\"{$column}_desc\" action=\"setSort\"");
    }
});
