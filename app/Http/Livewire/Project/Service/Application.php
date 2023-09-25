<?php

namespace App\Http\Livewire\Project\Service;

use App\Models\ServiceApplication;
use Livewire\Component;

class Application extends Component
{
    public ServiceApplication $application;
    public $fileStorages = null;
    protected $listeners = ["refreshFileStorages"];
    protected $rules = [
        'application.human_name' => 'nullable',
        'application.description' => 'nullable',
        'application.fqdn' => 'nullable',
    ];
    public function render()
    {
        return view('livewire.project.service.application');
    }
    public function refreshFileStorages()
    {
        $this->fileStorages = $this->application->fileStorages()->get();
    }
    public function mount()
    {
        $this->refreshFileStorages();
    }
    public function submit()
    {
        try {
            $this->validate();
            $this->application->save();
            $this->emit('success', 'Application saved successfully.');
        } catch (\Throwable $e) {
            ray($e);
        } finally {
            $this->emit('generateDockerCompose');
        }
    }
}
