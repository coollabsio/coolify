<?php

it('shows API token permissions in a dropdown and keeps non-root options rendered', function () {
    $view = file_get_contents(resource_path('views/livewire/security/api-tokens.blade.php'));
    $permissionsSection = str($view)->between(
        '<h4 class="text-[12px] font-semibold text-black dark:text-fg">Permissions</h4>',
        '</x-application.settings-section>'
    )->toString();

    expect($permissionsSection)
        ->toContain('permissionsOpen')
        ->toContain('Selected permissions')
        ->toContain('<x-forms.checkbox')
        ->toContain("in_array('root', \$permissions)")
        ->toContain('Read sensitive data')
        ->not->toContain('<input type="checkbox" value="root"')
        ->not->toContain("@if (!in_array('root', \$permissions))");
});
