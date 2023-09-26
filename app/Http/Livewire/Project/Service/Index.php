<?php

namespace App\Http\Livewire\Project\Service;

use App\Models\Service;
use DanHarrin\LivewireRateLimiting\WithRateLimiting;
use Livewire\Component;

class Index extends Component
{
    use WithRateLimiting;
    public Service $service;
    public $applications;
    public $databases;
    public array $parameters;
    public array $query;
    protected $rules = [
        'service.docker_compose_raw' => 'required',
        'service.docker_compose' => 'required',
        'service.name' => 'required',
        'service.description' => 'nullable',
    ];
    public function manualRefreshStack() {
        try {
            $this->rateLimit(5);
            $this->refreshStack();
        } catch(\Throwable $e) {
            return handleError($e, $this);
        }
    }
    public function refreshStack()
    {
        $this->applications = $this->service->applications->sort();
        $this->applications->each(function ($application) {
            $application->fileStorages()->get()->each(function ($fileStorage) use ($application) {
                if (!$fileStorage->is_directory && $fileStorage->content == null) {
                    $application->hasMissingFiles = true;
                }
            });
        });
        $this->databases = $this->service->databases->sort();
        $this->databases->each(function ($database) {
            $database->fileStorages()->get()->each(function ($fileStorage) use ($database) {
                if (!$fileStorage->is_directory && $fileStorage->content == null) {
                    $database->hasMissingFiles = true;
                }
            });
        });
        $this->emit('success', 'Stack refreshed successfully.');
    }
    public function mount()
    {
        $this->parameters = get_route_parameters();
        $this->query = request()->query();
        $this->service = Service::whereUuid($this->parameters['service_uuid'])->firstOrFail();
        $this->refreshStack();
    }
    public function render()
    {
        return view('livewire.project.service.index');
    }
    public function save()
    {
        try {
            $this->service->save();
            $this->service->parse();
            $this->service->refresh();
            $this->emit('refreshEnvs');
            $this->emit('success', 'Service saved successfully.');
            $this->service->saveComposeConfigs();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }
    public function submit()
    {
        try {
            $this->validate();
            $this->service->save();
            $this->emit('success', 'Service saved successfully.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }
}
