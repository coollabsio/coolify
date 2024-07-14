<?php

namespace App\Livewire\Settings;

use App\Actions\License\CheckResaleLicense;
use App\Models\InstanceSettings;
use Livewire\Component;

class License extends Component
{
    public InstanceSettings $settings;

    public ?string $instance_id = null;

    protected $rules = [
        'settings.resale_license' => 'nullable',
        'settings.is_resale_license_active' => 'nullable',
    ];

    protected $validationAttributes = [
        'settings.resale_license' => 'License',
        'instance_id' => 'Instance Id (Do not change this)',
        'settings.is_resale_license_active' => 'Is License Active',
    ];

    public function mount()
    {
        if (! isCloud()) {
            abort(404);
        }
        $this->instance_id = config('app.id');
        $this->settings = \App\Models\InstanceSettings::get();
    }

    public function render()
    {
        return view('livewire.settings.license');
    }

    public function submit()
    {
        $this->validate();
        $this->settings->save();
        if ($this->settings->resale_license) {
            try {
                CheckResaleLicense::run();
                $this->dispatch('reloadWindow');
            } catch (\Throwable $e) {
                session()->flash('error', 'Something went wrong. Please contact support. <br>Error: '.$e->getMessage());
                ray($e->getMessage());

                return redirect()->route('settings.license');
            }
        }
    }
}
