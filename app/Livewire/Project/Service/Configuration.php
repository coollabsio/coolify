<?php

namespace App\Livewire\Project\Service;

use App\Jobs\ContainerStatusJob;
use App\Models\Service;
use Livewire\Component;

class Configuration extends Component
{
    public ?Service $service = null;
    public $applications;
    public $databases;
    public array $parameters;
    public array $query;
    public function getListeners()
    {
        $userId = auth()->user()->id;
        return [
            "echo-private:user.{$userId},ServiceStatusChanged" => 'check_status',
            "check_status"
        ];
    }
    public function render()
    {
        return view('livewire.project.service.configuration');
    }
    public function mount()
    {
        $this->parameters = get_route_parameters();
        $this->query = request()->query();
        $this->service = Service::whereUuid($this->parameters['service_uuid'])->first();
        if (!$this->service) {
            return redirect()->route('dashboard');
        }
        $this->applications = $this->service->applications->sort();
        $this->databases = $this->service->databases->sort();
    }
    public function restartApplication($id)
    {
        try {
            $application = $this->service->applications->find($id);
            if ($application) {
                $application->restart();
                $this->dispatch('success', 'Application restarted successfully.');
            }
        } catch (\Exception $e) {
            return handleError($e, $this);
        }
    }
    public function restartDatabase($id)
    {
        try {
            $database = $this->service->databases->find($id);
            if ($database) {
                $database->restart();
                $this->dispatch('success', 'Database restarted successfully.');
            }
        } catch (\Exception $e) {
            return handleError($e, $this);
        }
    }
    public function check_status()
    {
        try {
            dispatch_sync(new ContainerStatusJob($this->service->server));
            $this->dispatch('refresh')->self();
            $this->dispatch('serviceStatusChanged');
        } catch (\Exception $e) {
            return handleError($e, $this);
        }
    }
}
