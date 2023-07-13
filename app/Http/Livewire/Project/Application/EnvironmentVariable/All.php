<?php

namespace App\Http\Livewire\Project\Application\EnvironmentVariable;

use App\Models\Application;
use App\Models\EnvironmentVariable;
use Livewire\Component;
use Visus\Cuid2\Cuid2;

class All extends Component
{
    public Application $application;
    public string|null $modalId = null;
    protected $listeners = ['refreshEnvs', 'submit'];
    public function mount()
    {
        $this->modalId = new Cuid2(7);
    }
    public function refreshEnvs()
    {
        $this->application->refresh();
    }
    public function submit($data)
    {
        try {
            $found = $this->application->environment_variables()->where('key', $data['key'])->first();
            if ($found) {
                $this->emit('error', 'Environment variable already exists.');
                return;
            }
            EnvironmentVariable::create([
                'key' => $data['key'],
                'value' => $data['value'],
                'is_build_time' => $data['is_build_time'],
                'is_preview' => $data['is_preview'],
                'application_id' => $this->application->id,
            ]);
            $this->application->refresh();

            $this->emit('success', 'Environment variable added successfully.');
        } catch (\Exception $e) {
            return general_error_handler(err: $e, that: $this);
        }
    }
}
