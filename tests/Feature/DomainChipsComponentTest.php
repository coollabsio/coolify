<?php

it('exposes enable and disable public access methods on service index', function () {
    $source = file_get_contents(app_path('Livewire/Project/Service/Index.php'));

    expect($source)
        ->toContain('public function enablePublicAccess(): void')
        ->toContain('public function disablePublicAccess(): void')
        ->toContain('$this->instantSave()');
});
