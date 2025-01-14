<?php

namespace App\View\Components\services;

use App\Models\Service;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

class advanced extends Component
{
    /**
     * Create a new component instance.
     */
    public function __construct(
        public ?Service $service = null
    ) {}

    /**
     * Get the view / contents that represent the component.
     */
    public function render(): View|Closure|string
    {
        return view('components.services.advanced');
    }
}
