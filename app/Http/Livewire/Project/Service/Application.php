<?php

namespace App\Http\Livewire\Project\Service;

use App\Models\ServiceApplication;
use Livewire\Component;

class Application extends Component
{
    public ServiceApplication $application;
    public $parameters;
    protected $rules = [
        'application.human_name' => 'nullable',
        'application.description' => 'nullable',
        'application.fqdn' => 'nullable',
        'application.image' => 'required',
        'application.exclude_from_status' => 'required|boolean',
        'application.required_fqdn' => 'required|boolean',
        'application.is_log_drain_enabled' => 'nullable|boolean',
    ];
    public function render()
    {
        return view('livewire.project.service.application');
    }
    public function instantSave()
    {
        $this->submit();
    }
    public function instantSaveAdvanced()
    {
        if (!$this->application->service->destination->server->isLogDrainEnabled()) {
            $this->application->is_log_drain_enabled = false;
            $this->emit('error', 'Log drain is not enabled on the server. Please enable it first.');
            return;
        }
        $this->application->save();
        $this->emit('success', 'You need to restart the service for the changes to take effect.');
    }
    public function delete()
    {
        try {
            $this->application->delete();
            $this->emit('success', 'Application deleted successfully.');
            return redirect()->route('project.service', $this->parameters);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }
    public function mount()
    {
        $this->parameters = get_route_parameters();
    }
    public function submit()
    {
        try {
            $this->validate();
            $this->application->save();
            updateCompose($this->application);
            $this->emit('success', 'Application saved successfully.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        } finally {
            $this->emit('generateDockerCompose');
        }
    }
}
