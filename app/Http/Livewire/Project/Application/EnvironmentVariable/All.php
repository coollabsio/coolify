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
                'application_id' => $this->application->id,
            ]);
            $this->application->refresh();
            $this->emit('clearAddEnv');
        } catch (\Exception $e) {
            return generalErrorHandler($e, $this);
        }
    }
}
