<?php

namespace App\View\Components;

use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

class Modal extends Component
{
    /**
     * Create a new component instance.
     */
    public function __construct(
        public string $modalId,
        public ?string $submitWireAction = null,
        public ?string $modalTitle = null,
        public ?string $modalBody = null,
        public ?string $modalSubmit = null,
        public bool $noSubmit = false,
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
