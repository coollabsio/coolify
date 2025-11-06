<?php

namespace App\Livewire\Project\Service;

use App\Models\Service;
use Livewire\Component;

class AutoPull extends Component
{
    public Service $service;

    public $auto_image_pull_enabled = false;

    public $auto_image_pull_schedule = 'daily';

    public function mount()
    {
        $this->auto_image_pull_enabled = $this->service->auto_image_pull_enabled ?? false;
        $this->auto_image_pull_schedule = $this->service->auto_image_pull_schedule ?? 'daily';
    }

    public function toggleAutoPull()
    {
        try {
            $this->auto_image_pull_enabled = ! $this->auto_image_pull_enabled;
            $this->service->auto_image_pull_enabled = $this->auto_image_pull_enabled;
            $this->service->save();
            $message = $this->auto_image_pull_enabled ? 'Automatic image pull enabled.' : 'Automatic image pull disabled.';
            $this->dispatch('success', $message);
        } catch (\Exception $e) {
            return handleError($e, $this);
        }
    }

    public function updateAutoPullSchedule()
    {
        try {
            $this->service->auto_image_pull_schedule = $this->auto_image_pull_schedule;
            $this->service->save();
            $this->dispatch('success', 'Auto pull schedule updated to '.$this->auto_image_pull_schedule.'.');
        } catch (\Exception $e) {
            return handleError($e, $this);
        }
    }

    public function checkForUpdates()
    {
        try {
            $this->service->last_image_pull_check = now();
            $this->service->save();
            $this->dispatch('success', 'Checked for image updates. Last check: '.$this->service->last_image_pull_check->diffForHumans());
        } catch (\Exception $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.project.service.auto-pull');
    }
}
