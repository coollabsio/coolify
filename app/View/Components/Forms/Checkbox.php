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
        public ?string     $id = null,
        public ?string     $name = null,
        public ?string     $value = null,
        public ?string     $label = null,
        public ?string     $helper = null,
        public string|bool $instantSave = false,
        public bool        $disabled = false,
        public string      $defaultClass = "border-coolgray-500 text-warning focus:ring-warning bg-coolgray-100 rounded cursor-pointer",
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
