<?php

namespace App\View\Components\Forms;

use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;
use Visus\Cuid2\Cuid2;

class Textarea extends Component
{
    /**
     * Create a new component instance.
     */
    public function __construct(
        public ?string $id = null,
        public ?string $name = null,
        public ?string $type = 'text',
        public ?string $value = null,
        public ?string $label = null,
        public ?string $placeholder = null,
        public bool    $required = false,
        public bool    $disabled = false,
        public bool    $readonly = false,
        public ?string $helper = null,
        public bool    $realtimeValidation = false,
        public bool    $allowToPeak = true,
        public string  $defaultClass = "block w-full py-1.5 rounded border-0 text-sm ring-inset ring-1 dark:bg-coolgray-100 dark:text-white text-black focus:ring-2 dark:focus:ring-coolgray-300 dark:ring-coolgray-300 scrollbar dark:read-only:text-neutral-500",
        public string  $defaultClassInput = "block w-full py-1.5 rounded border-0 text-sm ring-inset ring-1 dark:bg-coolgray-100 dark:text-white text-black focus:ring-2 dark:focus:ring-coolgray-300 dark:ring-coolgray-300 dark:read-only:text-neutral-500"
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
