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
        public string|null $id = null,
        public string|null $name = null,
        public string|null $value = null,
        public string|null $label = null,
        public string|null $helper = null,
        public string|bool        $instantSave = false,
        public bool        $disabled = false,
        public string      $defaultClass = "toggle toggle-xs toggle-warning rounded disabled:bg-coolgray-200 disabled:opacity-50 placeholder:text-neutral-700",
    ) {
        //
    }

    /**
     * Get the view / contents that represent the component.
     */
    public function render(): View|Closure|string
    {
        return view('components.forms.checkbox');
    }
}
