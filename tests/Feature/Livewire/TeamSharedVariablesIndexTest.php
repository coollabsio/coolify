<?php

use App\Livewire\TeamSharedVariablesIndex;
use Livewire\Livewire;

it('renders successfully', function () {
    Livewire::test(TeamSharedVariablesIndex::class)
        ->assertStatus(200);
});
