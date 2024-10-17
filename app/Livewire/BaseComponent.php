<?php

namespace App\Livewire;

use Livewire\Component;

abstract class BaseComponent extends Component
{
    public $parameters = [];

    public function boot()
    {
        $this->parameters = $this->getRouteParameters();
    }

    protected function getRouteParameters()
    {
        return get_route_parameters();
    }
}
