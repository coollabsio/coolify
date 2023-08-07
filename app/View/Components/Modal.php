<?php

namespace App\View\Components;

use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;
use Visus\Cuid2\Cuid2;

class Modal extends Component
{
    /**
     * Create a new component instance.
     */
    public function __construct(
        public string $modalId,
        public string|null $modalTitle = null,
        public string|null $modalBody = null,
        public string|null $modalSubmit = null,
        public bool $yesOrNo = false,
        public string $action = 'delete'
    ) {
        //
    }

    /**
     * Get the view / contents that represent the component.
     */
    public function render(): View|Closure|string
    {
        return view('components.modal');
    }
}