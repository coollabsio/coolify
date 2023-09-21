<?php

namespace App\Http\Livewire\Project\Service;

use App\Actions\Service\StartService;
use App\Jobs\ContainerStatusJob;
use App\Models\Service;
use Illuminate\Support\Collection;
use Livewire\Component;

class Index extends Component
{
    public Service $service;

    public array $parameters;
    public array $query;
    public Collection $services;

    protected $rules = [
        'services.*.fqdn' => 'nullable',
    ];

    public function mount()
    {
        $this->services = collect([]);
        $this->parameters = get_route_parameters();
        $this->query = request()->query();
        $this->service = Service::whereUuid($this->parameters['service_uuid'])->firstOrFail();
        foreach ($this->service->applications as $application) {
            $this->services->put($application->name, [
                'fqdn' => $application->fqdn,
            ]);
        }
        // foreach ($this->service->databases as $database) {
        //     $this->services->put($database->name, $database->fqdn);
        // }
    }
    public function render()
    {
        return view('livewire.project.service.index')->layout('layouts.app');
    }
    public function check_status()
    {
        dispatch_sync(new ContainerStatusJob($this->service->server));
        $this->service->refresh();

    }
    public function submit()
    {
        try {
            if ($this->services->count() === 0) {
                return;
            }
            foreach ($this->services as $name => $value) {
                $foundService = $this->service->applications()->whereName($name)->first();
                if ($foundService) {
                    $foundService->fqdn = data_get($value, 'fqdn');
                    $foundService->save();
                    return;
                }
                $foundService = $this->service->databases()->whereName($name)->first();
                if ($foundService) {
                    // $foundService->save();
                    return;
                }
            }
        } catch (\Throwable $e) {
            ray($e);
        } finally {
            $this->service->parse();
        }
    }
    public function deploy()
    {
        $this->service->parse();
        $activity = StartService::run($this->service);
        $this->emit('newMonitorActivity', $activity->id);
    }
}
