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
        public bool $noStyle = false,
        public ?string $modalId = null,
        public string $defaultClass = 'button',
        public bool $showLoadingIndicator = true,
    ) {
        if ($this->noStyle) {
            $this->defaultClass = '';
        }
    }

    public function render(): View|Closure|string
    {
        return view('components.forms.button');
    }
}
