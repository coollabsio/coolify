<?php

namespace App\View\Components\Forms;

use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\Component;
use Visus\Cuid2\Cuid2;

class Textarea extends Component
{
    public ?string $modelBinding = null;

    public ?string $htmlId = null;

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
        public ?string $monacoEditorLanguage = '',
        public bool $useMonacoEditor = false,
        public bool $required = false,
        public bool $disabled = false,
        public bool $readonly = false,
        public bool $allowTab = false,
        public bool $spellcheck = false,
        public bool $autofocus = false,
        public ?string $helper = null,
        public bool $realtimeValidation = false,
        public bool $allowToPeak = true,
        public string $defaultClass = 'input scrollbar font-mono',
        public string $defaultClassInput = 'input',
        public ?int $minlength = null,
        public ?int $maxlength = null,
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

        // $this->label = Str::title($this->label);
        return view('components.forms.textarea');
    }
}
