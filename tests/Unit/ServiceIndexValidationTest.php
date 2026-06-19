<?php

use App\Livewire\Project\Service\Index;

test('service database proxy timeout requires a minimum of one second', function () {
    $component = new Index;
    $rules = (fn (): array => $this->rules)->call($component);

    expect($rules['publicPortTimeout'])
        ->toContain('min:1');
});
