<?php

namespace App\Livewire;

use App\Actions\License\CheckResaleLicense;
use App\Models\InstanceSettings;
use Livewire\Component;

class CheckLicense extends Component
{
    public InstanceSettings|null $settings = null;
    public string|null $instance_id = null;
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
        $this->instance_id = config('app.id');
        $this->settings = InstanceSettings::get();
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
                session()->flash('error', 'Something went wrong. Please contact support. <br>Error: ' . $e->getMessage());
                ray($e->getMessage());
                return $this->redirect('/settings/license', navigate: true);
            }
        }
    }
}
