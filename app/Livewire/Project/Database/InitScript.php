<?php

namespace App\Livewire\Project\Database;

use Exception;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Validate;
use Livewire\Component;

class InitScript extends Component
{
    #[Locked]
    public array $script;

    #[Locked]
    public int $index;

    #[Validate(['nullable', 'string'])]
    public ?string $filename = null;

    #[Validate(['nullable', 'string'])]
    public ?string $content = null;

    public function mount()
    {
        try {
            $this->index = data_get($this->script, 'index');
            $this->filename = data_get($this->script, 'filename');
            $this->content = data_get($this->script, 'content');
        } catch (Exception $e) {
            return handleError($e, $this);
        }
    }

    public function submit()
    {
        try {
            $this->validate();
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
        try {
            $this->dispatch('delete_init_script', $this->script);
        } catch (Exception $e) {
            return handleError($e, $this);
        }
    }
}
