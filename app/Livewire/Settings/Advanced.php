<?php

namespace App\Livewire\Settings;

use App\Models\InstanceSettings;
use Livewire\Attributes\Validate;
use Livewire\Component;

class Advanced extends Component
    {
            public InstanceSettings $settings;

    #[Validate('boolean')]
            public bool $is_auto_update_enabled;

    #[Validate('boolean')]
            public bool $is_registration_enabled;

    #[Validate('boolean')]
            public bool $is_oauth_registration_enabled;

    #[Validate('boolean')]
            public bool $force_oauth_only_login;

    #[Validate('string|nullable')]
            public ?string $allowed_ips = null;

    protected $rules = [
                'is_auto_update_enabled' => 'required|boolean',
                'is_registration_enabled' => 'required|boolean',
                'is_oauth_registration_enabled' => 'required|boolean',
                'force_oauth_only_login' => 'required|boolean',
                'allowed_ips' => 'nullable|string',
            ];

    public function mount()
        {
                    $this->settings = instanceSettings();
                    $this->is_auto_update_enabled = $this->settings->is_auto_update_enabled;
                    $this->is_registration_enabled = $this->settings->is_registration_enabled;
                    $this->is_oauth_registration_enabled = $this->settings->is_oauth_registration_enabled;
                    $this->force_oauth_only_login = $this->settings->force_oauth_only_login;
                    $this->allowed_ips = $this->settings->allowed_ips;
        }

    public function submit()
        {
                    $this->validate();
                    $this->settings->update([
                                                        'is_auto_update_enabled' => $this->is_auto_update_enabled,
                                                        'is_registration_enabled' => $this->is_registration_enabled,
                                                        'is_oauth_registration_enabled' => $this->is_oauth_registration_enabled,
                                                        'force_oauth_only_login' => $this->force_oauth_only_login,
                                                        'allowed_ips' => $this->allowed_ips,
                                                    ]);
                    $this->dispatch('success', 'Settings updated.');
        }

    public function render()
        {
                    return view('livewire.settings.advanced');
        }
    }
