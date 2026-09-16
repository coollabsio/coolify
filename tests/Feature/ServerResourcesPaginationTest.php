<?php

use App\Livewire\Server\Resources;
use App\Models\Server;

beforeEach(function () {
    $this->component = new Resources;
    $this->component->server = Mockery::mock(Server::class)->makePartial();
});

it('paginates sorted resources on both tabs', function (string $tab, string $nameKey) {
    $rows = collect(range(23, 1))->map(fn (int $id) => [$nameKey => "Resource $id"]);
    $this->component->activeTab = $tab;
    if ($tab === 'managed') {
        $this->component->server->shouldReceive('definedResources')->andReturn($rows);
    } else {
        $this->component->unmanagedContainers = $rows->all();
    }

    $page = $this->component->render()->getData()['resources'];
    expect($page->total())->toBe(23)
        ->and($page->count())->toBe(10)
        ->and($page->pluck($nameKey)->all())->toBe(array_map(fn ($id) => "Resource $id", range(1, 10)));

    $this->component->nextPage();
    $page = $this->component->render()->getData()['resources'];
    expect($page->currentPage())->toBe(2)->and($page->count())->toBe(10)
        ->and($page->first()[$nameKey])->toBe('Resource 11');

    $this->component->nextPage();
    $page = $this->component->render()->getData()['resources'];
    expect($page->count())->toBe(3)->and($page->first()[$nameKey])->toBe('Resource 21');

    $this->component->previousPage();
    expect($this->component->render()->getData()['resources']->currentPage())->toBe(2);
})->with([['managed', 'name'], ['unmanaged', 'Names']]);

it('resets pagination and search when switching tabs but preserves them on refresh', function () {
    $this->component->server->shouldReceive('refresh')->andReturnSelf();
    $this->component->server->shouldReceive('loadUnmanagedContainers')->andReturn(collect());
    $this->component->setPage(3);
    $this->component->search = 'managed resource';
    $this->component->loadUnmanagedContainers();
    expect($this->component->getPage())->toBe(1)
        ->and($this->component->search)->toBe('');
    $this->component->setPage(2);
    $this->component->search = 'container';
    $this->component->loadUnmanagedContainers();
    expect($this->component->getPage())->toBe(2)
        ->and($this->component->search)->toBe('container');
    $this->component->loadManagedContainers();
    expect($this->component->getPage())->toBe(1)
        ->and($this->component->search)->toBe('');
    $this->component->setPage(2);
    $this->component->search = 'application';
    $this->component->loadManagedContainers();
    expect($this->component->getPage())->toBe(2)
        ->and($this->component->search)->toBe('application');
});

it('clamps page size and resets the page', function (int $size, int $expected) {
    $this->component->setPage(3);
    $this->component->perPage = $size;
    $this->component->updatedPerPage();
    expect($this->component->perPage)->toBe($expected)
        ->and($this->component->getPage())->toBe(1);
})->with([[25, 25], [0, 1], [200, 100]]);

it('clamps stale pages after resources disappear including an empty list', function (int $count, int $expectedPage) {
    $this->component->activeTab = 'unmanaged';
    $this->component->unmanagedContainers = array_fill(0, $count, ['Names' => 'Container']);
    $this->component->setPage(9);
    $page = $this->component->render()->getData()['resources'];
    expect($page->currentPage())->toBe($expectedPage)
        ->and($this->component->getPage())->toBe($expectedPage)
        ->and($page->total())->toBe($count);
})->with([[12, 2], [0, 1]]);

it('renders shared pagination for both resource tabs with stable row identities', function () {
    $view = file_get_contents(resource_path('views/livewire/server/resources.blade.php'));
    expect($view)->toContain('<x-table-pagination')
        ->toContain('<x-page-size-select')
        ->toContain('previous-action="previousPage"')
        ->toContain('next-action="nextPage"')
        ->toContain('wire:key="managed-')
        ->toContain('wire:key="unmanaged-')
        ->not->toContain('$server->definedResources()');
});

it('searches names across all pages before pagination on both tabs', function (string $tab, string $nameKey) {
    $rows = collect(range(1, 25))->map(fn (int $id) => [$nameKey => "Resource $id"]);
    $this->component->activeTab = $tab;
    if ($tab === 'managed') {
        $this->component->server->shouldReceive('definedResources')->andReturn($rows);
    } else {
        $this->component->unmanagedContainers = $rows->all();
    }

    $this->component->setPage(3);
    $this->component->search = '  RESOURCE 2  ';
    $this->component->updatedSearch();
    $page = $this->component->render()->getData()['resources'];
    expect($page->currentPage())->toBe(1)
        ->and($page->total())->toBe(7)
        ->and($page->pluck($nameKey)->all())->toBe([
            'Resource 2', 'Resource 20', 'Resource 21', 'Resource 22',
            'Resource 23', 'Resource 24', 'Resource 25',
        ]);

    $this->component->search = 'missing';
    $this->component->updatedSearch();
    expect($this->component->render()->getData()['resources']->total())->toBe(0);

    $this->component->search = '';
    $this->component->updatedSearch();
    $page = $this->component->render()->getData()['resources'];
    expect($page->total())->toBe(25)->and($page->currentPage())->toBe(1);

    $this->component->search = '   ';
    expect($this->component->render()->getData()['resources']->total())->toBe(25);
})->with([['managed', 'name'], ['unmanaged', 'Names']]);

it('provides accessible live search and a distinct no-results message', function () {
    $view = file_get_contents(resource_path('views/livewire/server/resources.blade.php'));

    expect($view)->toContain('wire:model.live.debounce.300ms="search"')
        ->toContain('aria-label="Search resources by name"')
        ->toContain('aria-label="Clear search"')
        ->toContain('No matching resources')
        ->toContain('No matching containers');
});

it('shows search feedback and prevents interaction with stale results while searching', function () {
    $view = file_get_contents(resource_path('views/livewire/server/resources.blade.php'));

    expect($view)
        ->toContain('<x-table.loading target="search" text="Searching resources..." />')
        ->not->toContain('wire:loading.inline-flex wire:target="search"')
        ->toContain('wire:loading.class="pointer-events-none opacity-40 blur-[2px]"')
        ->toContain('wire:loading.attr="inert" wire:target="search"');
});
