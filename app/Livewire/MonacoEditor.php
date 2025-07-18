<?php

namespace App\Livewire;

// use Livewire\Component;
use Illuminate\View\Component;
use Visus\Cuid2\Cuid2;

class MonacoEditor extends Component
{
    protected $listeners = [
        'configurationChanged' => '$refresh',
    ];

    public function __construct(
        public ?string $id,
        public ?string $name,
        public ?string $type,
        public ?string $monacoContent,
        public ?string $value,
        public ?string $label,
        public ?string $placeholder,
        public bool $required,
        public bool $disabled,
        public bool $readonly,
        public bool $allowTab,
        public bool $spellcheck,
        public ?string $helper,
        public bool $realtimeValidation,
        public bool $allowToPeak,
        public string $defaultClass,
        public string $defaultClassInput,
        public ?string $language

    ) {
        //
    }

    public function render()
    {
        if (is_null($this->id)) {
            $this->id = new Cuid2;
        }

        if (is_null($this->name)) {
            $this->name = $this->id;
        }

        return view('components.forms.monaco-editor');
    }
}
