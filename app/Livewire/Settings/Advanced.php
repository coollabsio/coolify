<?php

namespace App\Livewire\Settings;

use App\Models\InstanceSettings;
use App\Models\Server;
use Auth;
use Hash;
use Livewire\Attributes\Validate;
use Livewire\Component;

class Advanced extends Component
{
    #[Validate('required')]
    public Server $server;

    public InstanceSettings $settings;

    #[Validate('boolean')]
    public bool $is_registration_enabled;

    #[Validate('boolean')]
    public bool $do_not_track;

    #[Validate('boolean')]
    public bool $is_dns_validation_enabled;

    #[Validate('nullable|string')]
    public ?string $custom_dns_servers = null;

    #[Validate('boolean')]
    public bool $is_api_enabled;

    #[Validate('nullable|string')]
    public ?string $allowed_ips = null;

    #[Validate('boolean')]
    public bool $is_sponsorship_popup_enabled;

    #[Validate('boolean')]
    public bool $disable_two_step_confirmation;

    public function mount()
    {
        if (! isInstanceAdmin()) {
            return redirect()->route('dashboard');
        } else {
            $this->server = Server::findOrFail(0);
            $this->settings = instanceSettings();
            $this->custom_dns_servers = $this->settings->custom_dns_servers;
            $this->allowed_ips = $this->settings->allowed_ips;
            $this->do_not_track = $this->settings->do_not_track;
            $this->is_registration_enabled = $this->settings->is_registration_enabled;
            $this->is_dns_validation_enabled = $this->settings->is_dns_validation_enabled;
            $this->is_api_enabled = $this->settings->is_api_enabled;
            $this->disable_two_step_confirmation = $this->settings->disable_two_step_confirmation;
            $this->is_sponsorship_popup_enabled = $this->settings->is_sponsorship_popup_enabled;
        }
    }

    public function submit()
    {
        try {
            $this->validate();

            $this->custom_dns_servers = str($this->custom_dns_servers)->replaceEnd(',', '')->trim();
            $this->custom_dns_servers = str($this->custom_dns_servers)->trim()->explode(',')->map(function ($dns) {
                return str($dns)->trim()->lower();
            })->unique()->implode(',');

            $this->allowed_ips = str($this->allowed_ips)->replaceEnd(',', '')->trim();
            $this->allowed_ips = str($this->allowed_ips)->trim()->explode(',')->map(function ($ip) {
                return str($ip)->trim();
            })->unique()->implode(',');

            $this->instantSave();
        } catch (\Exception $e) {
            return handleError($e, $this);
        }
    }

    public function instantSave()
    {
        try {
            $this->settings->is_registration_enabled = $this->is_registration_enabled;
            $this->settings->do_not_track = $this->do_not_track;
            $this->settings->is_dns_validation_enabled = $this->is_dns_validation_enabled;
            $this->settings->custom_dns_servers = $this->custom_dns_servers;
            $this->settings->is_api_enabled = $this->is_api_enabled;
            $this->settings->allowed_ips = $this->allowed_ips;
            $this->settings->is_sponsorship_popup_enabled = $this->is_sponsorship_popup_enabled;
            $this->settings->disable_two_step_confirmation = $this->disable_two_step_confirmation;
            $this->settings->save();
            $this->dispatch('success', 'Settings updated!');
        } catch (\Exception $e) {
            return handleError($e, $this);
        }
    }

    public function toggleTwoStepConfirmation($password): bool
    {
        if (! Hash::check($password, Auth::user()->password)) {
            $this->addError('password', 'The provided password is incorrect.');

            return false;
        }

        $this->settings->disable_two_step_confirmation = $this->disable_two_step_confirmation = true;
        $this->settings->save();
        $this->dispatch('success', 'Two step confirmation has been disabled.');

        return true;
    }

    public function render()
    {
        return view('livewire.settings.advanced');
    }
}
