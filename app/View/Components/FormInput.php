<?php

namespace App\View\Components;

use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

class FormInput extends Component
{
    /**
     * Create a new component instance.
     */
    public function __construct(
        public string $id,
        public bool $required = false,
        public bool $readonly = false,
        public string|null $label = null,
        public string|null $type = 'text',
        public string|null $class = "",
        public bool $instantSave = false,
        public bool $disabled = false,
        public bool $hidden = false
    ) {
    }

    /**
     * Get the view / contents that represent the component.
     */
    public function render(): View|Closure|string
    {
        return view('components.form-input');
    }
}
