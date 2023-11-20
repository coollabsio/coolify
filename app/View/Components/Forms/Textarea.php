<?php

namespace App\View\Components\Forms;

use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Illuminate\View\Component;
use Visus\Cuid2\Cuid2;

class Textarea extends Component
{
    /**
     * Create a new component instance.
     */
    public function __construct(
        public string|null $id = null,
        public string|null $name = null,
        public string|null $type = 'text',
        public string|null $value = null,
        public string|null $label = null,
        public string|null $placeholder = null,
        public bool        $required = false,
        public bool        $disabled = false,
        public bool        $readonly = false,
        public string|null $helper = null,
        public bool        $realtimeValidation = false,
        public string      $defaultClass = "textarea leading-normal bg-coolgray-100 rounded text-white scrollbar disabled:bg-coolgray-200/50 disabled:border-none placeholder:text-coolgray-500 read-only:text-neutral-500 read-only:bg-coolgray-200/50"
    ) {
        //
    }

    /**
     * Get the view / contents that represent the component.
     */
    public function render(): View|Closure|string
    {
        if (is_null($this->id)) $this->id = new Cuid2(7);
        if (is_null($this->name)) $this->name = $this->id;

        // $this->label = Str::title($this->label);
        return view('components.forms.textarea');
    }
}
