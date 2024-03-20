<?php

namespace App\View\Components\Forms;

use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Illuminate\View\Component;
use Visus\Cuid2\Cuid2;

class Select extends Component
{
    /**
     * Create a new component instance.
     */
    public function __construct(
        public ?string $id = null,
        public ?string $name = null,
        public ?string $label = null,
        public ?string $helper = null,
        public bool    $required = false,
        public string  $defaultClass = "block w-full py-1.5 rounded border-0 text-sm ring-inset ring-1 dark:ring-coolgray-300 dark:placeholder:text-neutral-700 focus:ring-2 focus:ring-inset dark:focus:ring-coolgray-500 dark:bg-coolgray-100 dark:text-white text-black "
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

        $this->label = Str::title($this->label);
        return view('components.forms.select');
    }
}
