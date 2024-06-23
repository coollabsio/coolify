<?php

namespace App\Livewire;

//use Livewire\Component;
use Illuminate\View\Component;
use Visus\Cuid2\Cuid2;

class MonacoEditor extends Component
{
    protected $listeners = [
        'configurationChanged' => '$refresh',
    ];

    public $language;

    public function __construct(
        public ?string $id = null,
        public ?string $name = null,
        public ?string $type = 'text',
        public ?string $monacoContent = null,
        public ?string $value = null,
        public ?string $label = null,
        public ?string $placeholder = null,
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

    public function render()
    {
        if (is_null($this->id)) {
            $this->id = new Cuid2(7);
        }

        if (is_null($this->name)) {
            $this->name = $this->id;
        }

        return view('components.forms.monaco-editor');
    }
}
