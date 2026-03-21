<?php

it('keeps authorization props on the locked master domain router checkbox', function () {
    $view = file_get_contents(__DIR__.'/../../resources/views/livewire/server/show.blade.php');

    expect($view)->toContain('<x-forms.checkbox canGate="update" :canResource="$server" disabled instantSave')
        ->and($view)->toContain('id="isMasterDomainRouterEnabled"');
});
