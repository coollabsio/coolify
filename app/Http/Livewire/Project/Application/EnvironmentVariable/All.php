<?php

namespace App\Http\Livewire\Project\Application\EnvironmentVariable;

use App\Models\Application;
use App\Models\EnvironmentVariable;
use Livewire\Component;

class All extends Component
{
    public Application $application;
    protected $listeners = ['refreshEnvs', 'submit'];
    public function refreshEnvs()
    {
        $this->application->refresh();
    }
    public function submit($data)
    {
        try {
            EnvironmentVariable::create([
                'key' => $data['key'],
                'value' => $data['value'],
                'is_build_time' => $data['is_build_time'],
                'is_preview' => $data['is_preview'],
                'application_id' => $this->application->id,
            ]);
            $this->application->refresh();

            $this->emit('success', 'Environment variable added successfully.');
            $this->emit('clearAddEnv');
        } catch (\Exception $e) {
            return general_error_handler(err: $e, that: $this);
        }
    }
}
