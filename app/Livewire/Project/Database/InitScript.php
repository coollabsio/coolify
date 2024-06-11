<?php

namespace App\Livewire\Project\Database;

use Exception;
use Livewire\Component;

class InitScript extends Component
{
    public array $script;

    public int $index;

    public ?string $filename;

    public ?string $content;

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
            $this->dispatch('save_init_script', $this->script);
        } catch (Exception $e) {
            return handleError($e, $this);
        }
    }

    public function delete()
    {
        $this->dispatch('delete_init_script', $this->script);
    }
}
