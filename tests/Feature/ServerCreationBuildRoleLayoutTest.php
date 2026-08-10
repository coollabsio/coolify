<?php

test('server creation keeps private key actions together and advanced options collapsed', function () {
    $view = file_get_contents(resource_path('views/livewire/server/new/by-ip.blade.php'));

    expect($view)
        ->toContain('class="flex items-end gap-3"')
        ->toContain('<x-forms.collapsible class="mt-5 border-t border-neutral-200 pt-4 dark:border-white/[0.08]"')
        ->toContain('<x-forms.listbox id="is_build_server"')
        ->toContain('label="Use as a dedicated build server"')
        ->toContain("['value' => false, 'label' => 'No']")
        ->toContain("['value' => true, 'label' => 'Yes']")
        ->not->toContain('<x-forms.checkbox id="is_build_server"')
        ->toContain('helper="Build servers compile applications but do not host deployments. Enabling this makes the server build-only."');
});

test('server creation places the IP address and private key before optional details', function () {
    $view = file_get_contents(resource_path('views/livewire/server/new/by-ip.blade.php'));

    expect($view)
        ->toContain('class="mb-5"')
        ->and(strpos($view, 'id="ip"'))->toBeLessThan(strpos($view, 'id="private_key_id"'))
        ->and(strpos($view, 'id="private_key_id"'))->toBeLessThan(strpos($view, 'id="name"'));
});
