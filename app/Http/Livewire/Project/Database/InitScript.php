<?php

namespace App\Http\Livewire\Project\Database;

use Livewire\Component;

class InitScript extends Component
{
    public array $script;
    public int $index;
    public string|null $filename;
    public string|null $content;

    protected $rules = [
        'filename' => 'required|string',
        'content' => 'required|string',
    ];
    protected $validationAttributes = [
        'filename' => 'Filename',
        'content' => 'Content',
    ];

    public function mount()
    {
        $this->index = data_get($this->script, 'index');
        $this->filename = data_get($this->script, 'filename');
        $this->content = data_get($this->script, 'content');
    }

    public function submit()
    {
        $this->validate();
        try {
            $this->script['index'] = $this->index;
            $this->script['content'] = $this->content;
            $this->script['filename'] = $this->filename;
            $this->emitUp('save_init_script', $this->script);
        } catch (Exception $e) {
            return general_error_handler(err: $e, that: $this);
        }
    }

    public function delete()
    {
        $this->emitUp('delete_init_script', $this->script);
    }
}
