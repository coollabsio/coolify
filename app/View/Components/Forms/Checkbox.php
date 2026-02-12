<?php

namespace App\View\Components\Forms;

use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\Component;
use Visus\Cuid2\Cuid2;

class Checkbox extends Component
{
    public ?string $modelBinding = null;

    public ?string $htmlId = null;

    /**
     * Create a new component instance.
     */
    public function __construct(
        public ?string $id = null,
        public ?string $name = null,
        public ?string $value = null,
        public ?string $domValue = null,
        public ?string $label = null,
        public ?string $helper = null,
        public string|bool|null $checked = false,
        public string|bool $instantSave = false,
        public bool $disabled = false,
        public string $defaultClass = 'size-4 rounded border-neutral-300 text-coollabs dark:text-warning dark:border-coolgray-400 dark:bg-coolgray-200 focus:ring-coollabs dark:focus:ring-warning cursor-pointer disabled:cursor-not-allowed disabled:opacity-50',
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

        if ($this->disabled) {
            $this->defaultClass .= ' opacity-40';
        }
    }

    /**
     * Get the view / contents that represent the component.
     */
    public function render(): View|Closure|string
    {
        // Store original ID for wire:model binding (property name)
        $this->modelBinding = $this->id;

        // Generate unique HTML ID by adding random suffix
        // This prevents duplicate IDs when multiple forms are on the same page
        if ($this->id) {
            $uniqueSuffix = new Cuid2;
            $this->htmlId = $this->id.'-'.$uniqueSuffix;
        } else {
            $this->htmlId = $this->id;
        }

        return view('components.forms.checkbox');
    }
}
