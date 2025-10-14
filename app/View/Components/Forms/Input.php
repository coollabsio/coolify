<?php

namespace App\View\Components\Forms;

use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\Component;
use Visus\Cuid2\Cuid2;

class Input extends Component
{
    public ?string $modelBinding = null;

    public ?string $htmlId = null;

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
        public string $autocomplete = 'off',
        public ?int $minlength = null,
        public ?int $maxlength = null,
        public bool $autofocus = false,
        public ?string $canGate = null,
        public mixed $canResource = null,
        public bool $autoDisable = true,
    ) {
        // Handle authorization-based disabling
        if ($this->canGate && $this->canResource && $this->autoDisable) {
            $hasPermission = Gate::allows($this->canGate, $this->canResource);

            if (! $hasPermission) {
                $this->disabled = true;
            }
        }
    }

    public function render(): View|Closure|string
    {
        // Store original ID for wire:model binding (property name)
        $this->modelBinding = $this->id;

        if (is_null($this->id)) {
            $this->id = new Cuid2;
            $this->modelBinding = $this->id;
        }
        // Generate unique HTML ID by prefixing with Livewire component ID
        // This prevents duplicate IDs when multiple forms are on the same page
        $livewireId = $this->attributes?->wire('id');
        if ($livewireId && $this->modelBinding) {
            $this->htmlId = $livewireId.'-'.$this->modelBinding;
        } else {
            $this->htmlId = $this->modelBinding ?: $this->id;
        }

        if (is_null($this->name)) {
            $this->name = $this->modelBinding;
        }
        if ($this->type === 'password') {
            $this->defaultClass = $this->defaultClass.'  pr-[2.8rem]';
        }

        // $this->label = Str::title($this->label);
        return view('components.forms.input');
    }
}
