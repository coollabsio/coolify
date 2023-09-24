<?php

namespace App\Http\Livewire\Project\Service;

use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use Livewire\Component;

class Database extends Component
{
    public ServiceDatabase $database;
    protected $rules = [
        'database.human_name' => 'nullable',
        'database.description' => 'nullable',
    ];
    public function render()
    {
        return view('livewire.project.service.database');
    }
    public function submit()
    {
        try {
            $this->validate();
            $this->database->save();
        } catch (\Throwable $e) {
            ray($e);
        } finally {
            $this->emit('generateDockerCompose');
        }
    }
}
