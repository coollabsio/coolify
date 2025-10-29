<?php

namespace App\View\Components\Forms;

use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\Component;
use Visus\Cuid2\Cuid2;

class Select extends Component
{
    public ?string $modelBinding = null;

    public ?string $htmlId = null;

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
        public string $defaultClass = 'select w-full',
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

    /**
     * Get the view / contents that represent the component.
     */
    public function render(): View|Closure|string
    {
        // Store original ID for wire:model binding (property name)
        $this->modelBinding = $this->id;

        if (is_null($this->id)) {
            $this->id = new Cuid2;
            // Don't create wire:model binding for auto-generated IDs
            $this->modelBinding = 'null';
        }

        // Generate unique HTML ID by adding random suffix
        // This prevents duplicate IDs when multiple forms are on the same page
        if ($this->modelBinding && $this->modelBinding !== 'null') {
            // Use original ID with random suffix for uniqueness
            $uniqueSuffix = new Cuid2;
            $this->htmlId = $this->modelBinding.'-'.$uniqueSuffix;
        } else {
            $this->htmlId = (string) $this->id;
        }

        if (is_null($this->name)) {
            $this->name = $this->modelBinding !== 'null' ? $this->modelBinding : (string) $this->id;
        }

        return view('components.forms.select');
    }
}
