<?php

namespace App\Http\Livewire\Project\Service;

use App\Models\ServiceDatabase;
use Livewire\Component;

class Database extends Component
{
    public ServiceDatabase $database;
    public $fileStorages;
    protected $listeners = ["refreshFileStorages"];
    protected $rules = [
        'database.human_name' => 'nullable',
        'database.description' => 'nullable',
        'database.image' => 'required',
        'database.exclude_from_status' => 'required|boolean',
    ];
    public function render()
    {
        return view('livewire.project.service.database');
    }
    public function mount() {
        $this->refreshFileStorages();
    }
    public function instantSave() {
        $this->submit();
    }
    public function refreshFileStorages()
    {
        $this->fileStorages = $this->database->fileStorages()->get();
    }
    public function submit()
    {
        try {
            $this->validate();
            $this->database->save();
            updateCompose($this->database);
            $this->emit('success', 'Database saved successfully.');
        } catch (\Throwable $e) {
            ray($e);
        } finally {
            $this->emit('generateDockerCompose');
        }
    }
}
