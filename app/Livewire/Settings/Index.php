<?php

namespace App\Livewire\Settings;

use App\Jobs\CheckForUpdatesJob;
use App\Models\InstanceSettings;
use App\Models\Server;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Validate;
use Livewire\Component;

class Index extends Component
{
    public InstanceSettings $settings;

    protected Server $server;

    #[Validate('boolean')]
    public bool $is_auto_update_enabled;

    #[Validate('nullable|string|max:255')]
    public ?string $fqdn = null;

    #[Validate('required|integer|min:1025|max:65535')]
    public int $public_port_min;

    #[Validate('required|integer|min:1025|max:65535')]
    public int $public_port_max;

    #[Validate('nullable|string')]
    public ?string $custom_dns_servers = null;

    #[Validate('nullable|string|max:255')]
    public ?string $instance_name = null;

    #[Validate('nullable|string')]
    public ?string $allowed_ips = null;

    #[Validate('nullable|string')]
    public ?string $public_ipv4 = null;

    #[Validate('nullable|string')]
    public ?string $public_ipv6 = null;

    #[Validate('string')]
    public string $auto_update_frequency;

    #[Validate('string|required')]
    public string $update_check_frequency;

    #[Validate('required|string|timezone')]
    public string $instance_timezone;

    #[Validate('boolean')]
    public bool $do_not_track;

    #[Validate('boolean')]
    public bool $is_registration_enabled;

    #[Validate('boolean')]
    public bool $is_dns_validation_enabled;

    #[Validate('boolean')]
    public bool $is_api_enabled;

    #[Validate('boolean')]
    public bool $disable_two_step_confirmation;

    public function render()
    {
        return view('livewire.settings.index');
    }

    public function mount()
    {
        if (! isInstanceAdmin()) {
            return redirect()->route('dashboard');
        } else {
            $this->settings = instanceSettings();
            $this->fqdn = $this->settings->fqdn;
            $this->public_port_min = $this->settings->public_port_min;
            $this->public_port_max = $this->settings->public_port_max;
            $this->custom_dns_servers = $this->settings->custom_dns_servers;
            $this->instance_name = $this->settings->instance_name;
            $this->allowed_ips = $this->settings->allowed_ips;
            $this->public_ipv4 = $this->settings->public_ipv4;
            $this->public_ipv6 = $this->settings->public_ipv6;
            $this->do_not_track = $this->settings->do_not_track;
            $this->is_auto_update_enabled = $this->settings->is_auto_update_enabled;
            $this->is_registration_enabled = $this->settings->is_registration_enabled;
            $this->is_dns_validation_enabled = $this->settings->is_dns_validation_enabled;
            $this->is_api_enabled = $this->settings->is_api_enabled;
            $this->auto_update_frequency = $this->settings->auto_update_frequency;
            $this->update_check_frequency = $this->settings->update_check_frequency;
            $this->instance_timezone = $this->settings->instance_timezone;
            $this->disable_two_step_confirmation = $this->settings->disable_two_step_confirmation;
        }
    }

    #[Computed]
    public function timezones(): array
    {
        return collect(timezone_identifiers_list())
            ->sort()
            ->values()
            ->toArray();
    }

    public function instantSave($isSave = true)
    {
        $this->validate();
        if ($this->settings->is_auto_update_enabled === true) {
            $this->validate([
                'auto_update_frequency' => ['required', 'string'],
            ]);
        }

        $this->settings->fqdn = $this->fqdn;
        $this->settings->public_port_min = $this->public_port_min;
        $this->settings->public_port_max = $this->public_port_max;
        $this->settings->custom_dns_servers = $this->custom_dns_servers;
        $this->settings->instance_name = $this->instance_name;
        $this->settings->allowed_ips = $this->allowed_ips;
        $this->settings->public_ipv4 = $this->public_ipv4;
        $this->settings->public_ipv6 = $this->public_ipv6;
        $this->settings->do_not_track = $this->do_not_track;
        $this->settings->is_auto_update_enabled = $this->is_auto_update_enabled;
        $this->settings->is_registration_enabled = $this->is_registration_enabled;
        $this->settings->is_dns_validation_enabled = $this->is_dns_validation_enabled;
        $this->settings->is_api_enabled = $this->is_api_enabled;
        $this->settings->auto_update_frequency = $this->auto_update_frequency;
        $this->settings->update_check_frequency = $this->update_check_frequency;
        $this->settings->disable_two_step_confirmation = $this->disable_two_step_confirmation;
        $this->settings->instance_timezone = $this->instance_timezone;
        if ($isSave) {
            $this->settings->save();
            $this->dispatch('success', 'Settings updated!');
        }
    }

    public function submit()
    {
        try {
            $error_show = false;
            $this->server = Server::findOrFail(0);
            $this->resetErrorBag();

            if (! validate_timezone($this->instance_timezone)) {
                $this->instance_timezone = config('app.timezone');
                throw new \Exception('Invalid timezone.');
            } else {
                $this->settings->instance_timezone = $this->instance_timezone;
            }

            if ($this->settings->public_port_min > $this->settings->public_port_max) {
                $this->addError('settings.public_port_min', 'The minimum port must be lower than the maximum port.');

                return;
            }
            $this->validate();

            if ($this->is_auto_update_enabled && ! validate_cron_expression($this->auto_update_frequency)) {
                $this->dispatch('error', 'Invalid Cron / Human expression for Auto Update Frequency.');
                if (empty($this->auto_update_frequency)) {
                    $this->auto_update_frequency = '0 0 * * *';
                }

                return;
            }

            if (! validate_cron_expression($this->update_check_frequency)) {
                $this->dispatch('error', 'Invalid Cron / Human expression for Update Check Frequency.');
                if (empty($this->update_check_frequency)) {
                    $this->update_check_frequency = '0 * * * *';
                }

                return;
            }

            if ($this->settings->is_dns_validation_enabled && $this->settings->fqdn) {
                if (! validate_dns_entry($this->settings->fqdn, $this->server)) {
                    $this->dispatch('error', "Validating DNS failed.<br><br>Make sure you have added the DNS records correctly.<br><br>{$this->settings->fqdn}->{$this->server->ip}<br><br>Check this <a target='_blank' class='underline dark:text-white' href='https://coolify.io/docs/knowledge-base/dns-configuration'>documentation</a> for further help.");
                    $error_show = true;
                }
            }
            if ($this->settings->fqdn) {
                check_domain_usage(domain: $this->settings->fqdn);
            }
            $this->settings->custom_dns_servers = str($this->settings->custom_dns_servers)->replaceEnd(',', '')->trim();
            $this->settings->custom_dns_servers = str($this->settings->custom_dns_servers)->trim()->explode(',')->map(function ($dns) {
                return str($dns)->trim()->lower();
            });
            $this->settings->custom_dns_servers = $this->settings->custom_dns_servers->unique();
            $this->settings->custom_dns_servers = $this->settings->custom_dns_servers->implode(',');

            $this->settings->allowed_ips = str($this->settings->allowed_ips)->replaceEnd(',', '')->trim();
            $this->settings->allowed_ips = str($this->settings->allowed_ips)->trim()->explode(',')->map(function ($ip) {
                return str($ip)->trim();
            });
            $this->settings->allowed_ips = $this->settings->allowed_ips->unique();
            $this->settings->allowed_ips = $this->settings->allowed_ips->implode(',');

            $this->instantSave(isSave: false);

            $this->settings->save();
            $this->server->setupDynamicProxyConfiguration();
            if (! $error_show) {
                $this->dispatch('success', 'Instance settings updated successfully!');
            }
        } catch (\Exception $e) {
            return handleError($e, $this);
        }
    }

    public function checkManually()
    {
        CheckForUpdatesJob::dispatchSync();
        $this->dispatch('updateAvailable');
        $settings = instanceSettings();
        if ($settings->new_version_available) {
            $this->dispatch('success', 'New version available!');
        } else {
            $this->dispatch('success', 'No new version available.');
        }
    }

    public function toggleTwoStepConfirmation($password)
    {
        if (! Hash::check($password, Auth::user()->password)) {
            $this->addError('password', 'The provided password is incorrect.');

            return;
        }

        $this->settings->disable_two_step_confirmation = $this->disable_two_step_confirmation = true;
        $this->settings->save();
        $this->dispatch('success', 'Two step confirmation has been disabled.');
    }
}
