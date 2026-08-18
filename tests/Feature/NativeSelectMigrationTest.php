<?php

test('remaining user-facing dropdown fields use the shared animated listbox', function () {
    $views = [
        'livewire/source/gitlab/change.blade.php' => ['privateKeyId', 'webhook_endpoint'],
        'livewire/project/application/domains.blade.php' => ['newDomainService'],
        'livewire/project/service/domains.blade.php' => ['newServiceApplicationId'],
        'livewire/server/new/by-ip.blade.php' => ['is_build_server'],
    ];

    foreach ($views as $path => $ids) {
        $view = file_get_contents(resource_path('views/'.$path));

        foreach ($ids as $id) {
            expect($view)
                ->toContain('<x-forms.listbox')
                ->toContain('id="'.$id.'"')
                ->not->toMatch('/<x-forms\.select[^>]*id="'.preg_quote($id, '/').'"/s');
        }
    }
});
