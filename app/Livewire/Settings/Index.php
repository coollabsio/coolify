<?php

namespace App\Livewire\Settings;

use App\Models\InstanceSettings;
use App\Models\Server;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Validate;
use Livewire\Component;

class Index extends Component
{
    public InstanceSettings $settings;

    public Server $server;

    #[Validate('nullable|string|max:255')]
    public ?string $fqdn = null;

    #[Validate('required|integer|min:1025|max:65535')]
    public int $public_port_min;

    #[Validate('required|integer|min:1025|max:65535')]
    public int $public_port_max;

    #[Validate('nullable|string|max:255')]
    public ?string $instance_name = null;

    #[Validate('nullable|string')]
    public ?string $public_ipv4 = null;

    #[Validate('nullable|string')]
    public ?string $public_ipv6 = null;

    #[Validate('required|string|timezone')]
    public string $instance_timezone;

    public array $domainConflicts = [];

    public bool $showDomainConflictModal = false;

    public bool $forceSaveDomains = false;

    public function render()
    {
        return view('livewire.settings.index');
    }

    public function mount()
    {
        if (! isInstanceAdmin()) {
            return redirect()->route('dashboard');
        }
        $this->settings = instanceSettings();
        $this->server = Server::findOrFail(0);
        $this->fqdn = $this->settings->fqdn;
        $this->public_port_min = $this->settings->public_port_min;
        $this->public_port_max = $this->settings->public_port_max;
        $this->instance_name = $this->settings->instance_name;
        $this->public_ipv4 = $this->settings->public_ipv4;
        $this->public_ipv6 = $this->settings->public_ipv6;
        $this->instance_timezone = $this->settings->instance_timezone;
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
        $this->settings->fqdn = $this->fqdn;
        $this->settings->public_port_min = $this->public_port_min;
        $this->settings->public_port_max = $this->public_port_max;
        $this->settings->instance_name = $this->instance_name;
        $this->settings->public_ipv4 = $this->public_ipv4;
        $this->settings->public_ipv6 = $this->public_ipv6;
        $this->settings->instance_timezone = $this->instance_timezone;
        if ($isSave) {
            $this->settings->save();
            $this->dispatch('success', 'Settings updated!');
        }
    }

    public function confirmDomainUsage()
    {
        $this->forceSaveDomains = true;
        $this->showDomainConflictModal = false;
        $this->submit();
    }

    public function submit()
    {
        try {
            $error_show = false;
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

            if ($this->settings->is_dns_validation_enabled && $this->fqdn) {
                if (! validateDNSEntry($this->fqdn, $this->server)) {
                    $this->dispatch('error', "Validating DNS failed.<br><br>Make sure you have added the DNS records correctly.<br><br>{$this->fqdn}->{$this->server->ip}<br><br>Check this <a target='_blank' class='underline dark:text-white' href='https://coolify.io/docs/knowledge-base/dns-configuration'>documentation</a> for further help.");
                    $error_show = true;
                }
            }
            if ($this->fqdn) {
                if (! $this->forceSaveDomains) {
                    $result = checkDomainUsage(domain: $this->fqdn);
                    if ($result['hasConflicts']) {
                        $this->domainConflicts = $result['conflicts'];
                        $this->showDomainConflictModal = true;

                        return;
                    }
                } else {
                    // Reset the force flag after using it
                    $this->forceSaveDomains = false;
                }
            }

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
}
