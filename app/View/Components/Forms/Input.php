<?php

namespace App\View\Components\Forms;

use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;
use Visus\Cuid2\Cuid2;

class Input extends Component
{
    public function __construct(
        public ?string $id = null,
        public ?string $name = null,
        public ?string $type = 'text',
        public ?string $value = null,
        public ?string $label = null,
        public bool $required = false,
        public bool $disabled = false,
        public bool $readonly = false,
        public ?string $helper = null,
        public bool $allowToPeak = true,
        public bool $isMultiline = false,
        public string $defaultClass = 'input',
    ) {}

    public function render(): View|Closure|string
    {
        if (is_null($this->id)) {
            $this->id = new Cuid2;
        }
        if (is_null($this->name)) {
            $this->name = $this->id;
        }
        if ($this->type === 'password') {
            $this->defaultClass = $this->defaultClass.'  pr-[2.8rem]';
        }

        // $this->label = Str::title($this->label);
        return view('components.forms.input');
    }
}
