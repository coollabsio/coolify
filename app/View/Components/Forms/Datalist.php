<?php

namespace App\View\Components\Forms;

use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\Component;
use Visus\Cuid2\Cuid2;

class Datalist extends Component
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
        public bool $disabled = false,
        public bool $readonly = false,
        public bool $multiple = false,
        public string|bool $instantSave = false,
        public ?string $value = null,
        public ?string $placeholder = null,
        public bool $autofocus = false,
        public string $defaultClass = 'input',
        public ?string $canGate = null,
        public mixed $canResource = null,
        public bool $autoDisable = true,
    ) {
        // Handle authorization-based disabling
        if ($this->canGate && $this->canResource && $this->autoDisable) {
            $hasPermission = Gate::allows($this->canGate, $this->canResource);

            if (! $hasPermission) {
                $this->disabled = true;
                $this->instantSave = false; // Disable instant save for unauthorized users
            }
        }
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

        return view('components.forms.datalist');
    }
}
