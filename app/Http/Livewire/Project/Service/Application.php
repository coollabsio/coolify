<?php

namespace App\Http\Livewire\Project\Service;

use App\Models\ServiceApplication;
use Livewire\Component;

class Application extends Component
{
    public ServiceApplication $application;
    public $parameters;
    public $fileStorages = null;
    protected $listeners = ["refreshFileStorages"];
    protected $rules = [
        'application.human_name' => 'nullable',
        'application.description' => 'nullable',
        'application.fqdn' => 'nullable',
        'application.image_tag' => 'required',
        'application.ignore_from_status' => 'required|boolean',
    ];
    public function render()
    {
        return view('livewire.project.service.application');
    }
    public function instantSave() {
        $this->submit();
    }
    public function refreshFileStorages()
    {
        $this->fileStorages = $this->application->fileStorages()->get();
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
