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
        public bool $required = false,
        public string $defaultClass = 'select'
    ) {
        //
    }

    /**
     * Get the view / contents that represent the component.
     */
    public function render(): View|Closure|string
    {
        if (is_null($this->id)) {
            $this->id = new Cuid2;
        }
        if (is_null($this->name)) {
            $this->name = $this->id;
        }

        $this->label = Str::title($this->label);

        return view('components.forms.select');
    }
}
