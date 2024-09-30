<?php

namespace App\View\Components;

use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

class ResourceView extends Component
{
    /**
     * Create a new component instance.
     */
    public function __construct(
        public ?string $wire = null,
        public ?string $logo = null,
        public ?string $documentation = null,
        public bool $upgrade = false,
    ) {}

    /**
     * Get the view / contents that represent the component.
     */
    public function render(): View|Closure|string
    {
        return view('components.resource-view');
    }
}
