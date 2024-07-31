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
        public ?string $monacoEditorLanguage = '',
        public bool $useMonacoEditor = false,
        public bool $required = false,
        public bool $disabled = false,
        public bool $readonly = false,
        public bool $allowTab = false,
        public bool $spellcheck = false,
        public ?string $helper = null,
        public bool $realtimeValidation = false,
        public bool $allowToPeak = true,
        public string $defaultClass = 'input scrollbar font-mono',
        public string $defaultClassInput = 'input'
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

        // $this->label = Str::title($this->label);
        return view('components.forms.textarea');
    }
}
