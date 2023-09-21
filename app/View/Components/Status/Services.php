<?php

namespace App\View\Components\Status;

use App\Models\Service;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

class Services extends Component
{
    /**
     * Create a new component instance.
     */
    public function __construct(
        public Service $service,
        public string $complexStatus = 'exited',
    ) {
        $foundRunning = false;
        $isDegraded = false;
        $applications = $service->applications;
        $databases = $service->databases;
        foreach ($applications as $application) {
            if ($application->status === 'running') {
                $foundRunning = true;
            } else {
                $isDegraded = true;
            }
        }
        foreach ($databases as $database) {
            if ($database->status === 'running') {
                $foundRunning = true;
            } else {
                $isDegraded = true;
            }
        }
        if ($foundRunning && !$isDegraded) {
            $this->complexStatus = 'running';
        } else if ($foundRunning && $isDegraded) {
            $this->complexStatus = 'degraded';
        } else if (!$foundRunning && $isDegraded) {
            $this->complexStatus = 'exited';
        }
    }

    /**
     * Get the view / contents that represent the component.
     */
    public function render(): View|Closure|string
    {
        return view('components.status.services');
    }
}
