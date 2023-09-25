<?php

namespace App\Http\Livewire\Project\Service;

use App\Models\ServiceDatabase;
use Livewire\Component;

class Database extends Component
{
    public ServiceDatabase $database;
    protected $rules = [
        'database.human_name' => 'nullable',
        'database.description' => 'nullable',
        'database.image_tag' => 'required',
        'database.ignore_from_status' => 'required|boolean',

    ];
    public function render()
    {
        return view('livewire.project.service.database');
    }
    public function instantSave() {
        $this->submit();
    }
    public function submit()
    {
        try {
            $this->validate();
            $this->database->save();
            $this->emit('success', 'Database saved successfully.');
        } catch (\Throwable $e) {
            ray($e);
        } finally {
            $this->emit('generateDockerCompose');
        }
    }
}
