<?php

it('shows local host vitals only to self-hosted instance admins', function () {
    $layout = file_get_contents(resource_path('views/layouts/app.blade.php'));

    expect($layout)
        ->toContain('@if (isInstanceAdmin() && ! isCloud())')
        ->toContain('<livewire:host-vitals />');
});

it('keeps host vitals polling conservative in the desktop top bar', function () {
    $view = file_get_contents(resource_path('views/livewire/host-vitals.blade.php'));

    expect($view)
        ->toContain('wire:init="refreshVitals"')
        ->toContain('wire:poll.15s="refreshVitals"')
        ->toContain('min-[1440px]:flex')
        ->toContain('min-[1900px]:flex');
});

it('reads cpu memory disk and load from local host sources', function () {
    $component = file_get_contents(app_path('Livewire/HostVitals.php'));

    expect($component)
        ->toContain("file_get_contents('/proc/cpuinfo')")
        ->toContain("file_get_contents('/proc/meminfo')")
        ->toContain("disk_total_space('/')")
        ->toContain("disk_free_space('/')")
        ->toContain('sys_getloadavg()')
        ->toContain('isCloud() || ! isInstanceAdmin()');
});
