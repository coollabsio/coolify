<?php

namespace App\Http\Livewire\Project\Service;

use App\Models\ServiceApplication;
use Livewire\Component;

class Application extends Component
{
    public ServiceApplication $application;
    protected $rules = [
        'application.human_name' => 'nullable',
        'application.description' => 'nullable',
        'application.fqdn' => 'nullable',
    ];
    public function render()
    {
        ray($this->application->fileStorages()->get());
        return view('livewire.project.service.application');
    }
    public function submit()
    {
        try {
            $this->validate();
            $this->application->save();
        } catch (\Throwable $e) {
            ray($e);
        } finally {
            $this->emit('generateDockerCompose');
        }
    }
}
