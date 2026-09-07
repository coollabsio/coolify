<?php

it('includes v5 applications in the v4 resource index and links them to v5', function () {
    $component = file_get_contents(app_path('Livewire/Project/Resource/Index.php'));
    $view = file_get_contents(resource_path('views/livewire/project/resource/index.blade.php'));

    expect($component)
        ->toContain('V5Application')
        ->toContain("route('v5.dashboard'")
        ->toContain("? 'v5' : 'v4'")
        ->and($view)
        ->toContain("item.version === 'v5'")
        ->toContain('V5');
});
