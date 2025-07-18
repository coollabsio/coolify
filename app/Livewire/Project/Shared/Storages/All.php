<?php

namespace App\Livewire\Project\Shared\Storages;

use Livewire\Component;

class All extends Component
{
    public $resource;

    protected $listeners = ['refreshStorages' => '$refresh'];
}
