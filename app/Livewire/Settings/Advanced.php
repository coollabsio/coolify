<?php

namespace App\Livewire\Settings;

use App\Models\InstanceSettings;
use App\Models\Server;
use App\Rules\ValidIpOrCidr;
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

    public ?string $allowed_ips = null;

    #[Validate('boolean')]
    public bool $is_sponsorship_popup_enabled;

    #[Validate('boolean')]
    public bool $disable_two_step_confirmation;

    public function rules()
    {
        return [
            'server' => 'required',
            'is_registration_enabled' => 'boolean',
            'do_not_track' => 'boolean',
            'is_dns_validation_enabled' => 'boolean',
            'custom_dns_servers' => 'nullable|string',
            'is_api_enabled' => 'boolean',
            'allowed_ips' => ['nullable', 'string', new ValidIpOrCidr],
            'is_sponsorship_popup_enabled' => 'boolean',
            'disable_two_step_confirmation' => 'boolean',
        ];
    }

    public function mount()
    {
        if (! isInstanceAdmin()) {
            return redirect()->route('dashboard');
        }
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

    public function submit()
    {
        try {
            $this->validate();

            $this->custom_dns_servers = str($this->custom_dns_servers)->replaceEnd(',', '')->trim();
            $this->custom_dns_servers = str($this->custom_dns_servers)->trim()->explode(',')->map(function ($dns) {
                return str($dns)->trim()->lower();
            })->unique()->implode(',');

            // Handle allowed IPs with subnet support and 0.0.0.0 special case
            $this->allowed_ips = str($this->allowed_ips)->replaceEnd(',', '')->trim();

            // Only validate and clean up if we have IPs and it's not 0.0.0.0 (allow all)
            if (! empty($this->allowed_ips) && ! in_array('0.0.0.0', array_map('trim', explode(',', $this->allowed_ips)))) {
                $invalidEntries = [];
                $validEntries = str($this->allowed_ips)->trim()->explode(',')->map(function ($entry) use (&$invalidEntries) {
                    $entry = str($entry)->trim()->toString();

                    if (empty($entry)) {
                        return null;
                    }

                    // Check if it's valid CIDR notation
                    if (str_contains($entry, '/')) {
                        [$ip, $mask] = explode('/', $entry);
                        if (filter_var($ip, FILTER_VALIDATE_IP) && is_numeric($mask) && $mask >= 0 && $mask <= 32) {
                            return $entry;
                        }
                        $invalidEntries[] = $entry;

                        return null;
                    }

                    // Check if it's a valid IP address
                    if (filter_var($entry, FILTER_VALIDATE_IP)) {
                        return $entry;
                    }

                    $invalidEntries[] = $entry;

                    return null;
                })->filter()->unique();

                if (! empty($invalidEntries)) {
                    $this->dispatch('error', 'Invalid IP addresses or subnets: '.implode(', ', $invalidEntries));

                    return;
                }

                if ($validEntries->isEmpty()) {
                    $this->dispatch('error', 'No valid IP addresses or subnets provided');

                    return;
                }

                $this->allowed_ips = $validEntries->implode(',');
            }

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
