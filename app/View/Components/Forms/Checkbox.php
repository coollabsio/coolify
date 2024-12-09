<?php

namespace App\View\Components\Forms;

use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

class Checkbox extends Component
{
    /**
     * Create a new component instance.
     */
    public function __construct(
        public ?string $id = null,
        public ?string $name = null,
        public ?string $value = null,
        public ?string $domValue = null,
        public ?string $label = null,
        public ?string $helper = null,
        public string|bool|null $checked = false,
        public string|bool $instantSave = false,
        public bool $disabled = false,
        public string $defaultClass = 'dark:border-neutral-700 text-coolgray-400 focus:ring-warning dark:bg-coolgray-100 rounded cursor-pointer dark:disabled:bg-base dark:disabled:cursor-not-allowed',
    ) {
        if ($this->disabled) {
            $this->defaultClass .= ' opacity-40';
        }
    }

    /**
     * Get the view / contents that represent the component.
     */
    public function render(): View|Closure|string
    {
        return view('components.forms.checkbox');
    }
}
