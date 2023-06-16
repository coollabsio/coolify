<?php

namespace App\View\Components\Forms;

use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;
use Visus\Cuid2\Cuid2;
use Illuminate\Support\Str;

class Input extends Component
{
    public function __construct(
        public string|null $id = null,
        public string|null $name = null,
        public string|null $type = 'text',
        public string|null $value = null,
        public string|null $label = null,
        public string|null $placeholder = null,
        public bool $required = false,
        public bool $disabled = false,
        public bool $readonly = false,
        public string|null $helper = null,
        public bool $noDirty = false,
        public bool $cannotPeakPassword = false,
    ) {
    }

    public function render(): View|Closure|string
    {
        if (is_null($this->id)) $this->id = new Cuid2(7);
        if (is_null($this->name)) $this->name = $this->id;

        $this->label = Str::title($this->label);
        return view('components.forms.input');
    }
}
