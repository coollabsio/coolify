<?php

use Illuminate\Support\Facades\Blade;

it('registers broom, shield-star, and network reicons for server sidebar items', function (string $name) {
    $contents = file_get_contents(resource_path('views/components/reicon.blade.php'));

    expect($contents)->toContain("'{$name}' => ");

    $html = Blade::render("<x-reicon name=\"{$name}\" class=\"menu-item-icon\" />");

    expect($html)
        ->toContain('viewBox="0 0 24 24"')
        ->toContain('fill="currentColor"')
        ->toContain('menu-item-icon')
        ->not->toBe('<svg class="menu-item-icon size-4" viewBox="0 0 24 24" fill="none"
    xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
    
</svg>');
})->with(['broom', 'shield-star', 'network']);

it('uses the broom reicon for docker cleanup in the server sidebar', function () {
    $contents = file_get_contents(resource_path('views/components/server/sidebar.blade.php'));

    expect($contents)
        ->toContain("'label' => 'Docker Cleanup'")
        ->toContain("'icon' => 'broom'")
        ->not->toMatch("/'label' => 'Docker Cleanup',\s*'route' => 'server\.docker-cleanup',\s*'active' => \$activeMenu === 'docker-cleanup',\s*'icon' => 'storages'/s");
});

it('uses the shield-star reicon for sentinel in the server sidebar', function () {
    $contents = file_get_contents(resource_path('views/components/server/sidebar.blade.php'));

    expect($contents)
        ->toContain("'label' => 'Sentinel'")
        ->toMatch("/'label' => 'Sentinel',\s*'route' => 'server\.sentinel',\s*'active' => request\(\)->routeIs\('server\.sentinel', 'server\.sentinel\.\*'\),\s*'icon' => 'shield-star'/s");
});

it('uses the network reicon for proxy in the server sidebar', function () {
    $contents = file_get_contents(resource_path('views/components/server/sidebar.blade.php'));

    expect($contents)
        ->toContain("'label' => 'Proxy'")
        ->toContain("'route' => 'server.proxy'")
        ->toContain("'icon' => 'network'")
        ->toMatch("/'label' => 'Proxy'[\s\S]*?'icon' => 'network'/")
        ->not->toMatch("/'label' => 'Proxy'[\s\S]*?'icon' => 'settings',\s*'group' => 'Platform'/");
});
