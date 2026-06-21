<?php

namespace App\View\Components\Forms;

use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
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
        public ?string $canGate = null,
        public mixed $canResource = null,
        public bool $autoDisable = true,
    ) {
        // Handle authorization-based disabling
        if ($this->canGate && $this->canResource && $this->autoDisable) {
            $hasPermission = Gate::allows($this->canGate, $this->canResource);

            if (! $hasPermission) {
                $this->disabled = true;
            }
        }

        if ($this->noStyle) {
            $this->defaultClass = '';
        }
    }

    public function render(): View|Closure|string
    {
        return view('components.forms.button');
    }
}
