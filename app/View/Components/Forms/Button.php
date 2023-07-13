<?php

namespace App\View\Components\Forms;

use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

class Button extends Component
{
    /**
     * Create a new component instance.
     */
    public function __construct(
        public bool $disabled = false,
        public bool $isModal = false,
        public string|null $modalId = null,
        public string $defaultClass = "btn btn-primary btn-xs text-white normal-case no-animation rounded border-none"
    ) {
        //
    }

    /**
     * Get the view / contents that represent the component.
     */
    public function render(): View|Closure|string
    {
        return view('components.forms.button');
    }
}
