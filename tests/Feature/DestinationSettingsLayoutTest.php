<?php

it('uses sidebar navigation and breadcrumbs for destination details', function () {
    $show = file_get_contents(resource_path('views/livewire/destination/show.blade.php'));
    $resources = file_get_contents(resource_path('views/livewire/destination/resources.blade.php'));
    $sidebar = file_get_contents(resource_path('views/livewire/destination/sidebar.blade.php'));
    $navbar = file_get_contents(resource_path('views/components/dashboard/navbar.blade.php'));
    $breadcrumbs = file_get_contents(resource_path('views/components/top-breadcrumb.blade.php'));

    expect($show)
        ->toContain(':mobileTitleOnly="true"')
        ->toContain("@include('livewire.destination.sidebar'");
    expect($resources)
        ->toContain(':mobileTitleOnly="true"')
        ->toContain("@include('livewire.destination.sidebar'");
    expect($sidebar)
        ->toContain("'label' => 'General'")
        ->toContain("'label' => 'Resources'")
        ->toContain("'label' => 'Danger Zone'")
        ->toContain('application-settings-navigation min-w-0 xl:self-start');
    expect($navbar)
        ->toContain("request()->routeIs('destination.show', 'destination.resources', 'destination.danger')")
        ->not->toContain("['label' => 'Resources', 'route' => 'destination.resources'");
    expect($breadcrumbs)
        ->toContain('x-breadcrumb-switcher')
        ->toContain('$currentDestination->name')
        ->toContain("route('destination.show'")
        ->toContain("? 'Docker' : 'Deprecated'");
    expect($breadcrumbs)
        ->toContain('Pages')
        ->toContain('Projects')
        ->toContain('Environments')
        ->not->toContain('M5 12l5 5 9-11');
    expect($show)
        ->toContain('destination-danger-section')
        ->toContain('title="Danger zone"')
        ->not->toContain("actions=['This will delete the selected destination/network.']");
    expect(file_get_contents(base_path('routes/web.php')))
        ->toContain("->name('destination.danger')");
});
