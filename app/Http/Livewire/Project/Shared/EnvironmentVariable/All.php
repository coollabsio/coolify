<?php

namespace App\Http\Livewire\Project\Shared\EnvironmentVariable;

use App\Models\EnvironmentVariable;
use Livewire\Component;
use Visus\Cuid2\Cuid2;

class All extends Component
{
    public $resource;
    public string|null $modalId = null;
    protected $listeners = ['refreshEnvs', 'submit'];

    public function mount()
    {
        $this->modalId = new Cuid2(7);
    }

    public function refreshEnvs()
    {
        $this->resource->refresh();
    }

    public function submit($data)
    {
        try {
            $found = $this->resource->environment_variables()->where('key', $data['key'])->first();
            if ($found) {
                $this->emit('error', 'Environment variable already exists.');
                return;
            }
            $environment = new EnvironmentVariable();
            $environment->key = $data['key'];
            $environment->value = $data['value'];
            $environment->is_build_time = $data['is_build_time'];
            $environment->is_preview = $data['is_preview'];

            if ($this->resource->type() === 'application') {
                $environment->application_id = $this->resource->id;
            }
            if ($this->resource->type() === 'standalone-postgresql') {
                $environment->standalone_postgresql_id = $this->resource->id;
            }
            $environment->save();
            $this->resource->refresh();
            $this->emit('success', 'Environment variable added successfully.');
        } catch (\Exception $e) {
            return general_error_handler(err: $e, that: $this);
        }
    }
}
