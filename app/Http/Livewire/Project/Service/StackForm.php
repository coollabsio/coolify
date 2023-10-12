<?php

namespace App\Http\Livewire\Project\Service;

use Livewire\Component;

class StackForm extends Component
{
    protected $listeners = ["saveCompose"];
    protected $rules = [
        'service.docker_compose_raw' => 'required',
        'service.docker_compose' => 'required',
        'service.name' => 'required',
        'service.description' => 'nullable',
    ];
    public $service;
    public function saveCompose($raw)
    {
        $this->service->docker_compose_raw = $raw;
        $this->submit();
    }

    public function submit()
    {
        try {
            $this->validate();
            $this->service->save();
            $this->service->parse();
            $this->service->refresh();
            $this->service->saveComposeConfigs();
            $this->emit('refreshStacks');
            $this->emit('refreshEnvs');
            $this->emit('success', 'Service saved successfully.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }
    public function render()
    {
        return view('livewire.project.service.stack-form');
    }
}
