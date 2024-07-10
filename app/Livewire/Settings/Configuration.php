<?php

namespace App\Livewire\Settings;

use App\Models\InstanceSettings as ModelsInstanceSettings;
use App\Models\Server;
use Livewire\Component;

class Configuration extends Component
{
    public ModelsInstanceSettings $settings;

    public bool $do_not_track;

    public bool $is_auto_update_enabled;

    public bool $is_registration_enabled;

    public bool $is_dns_validation_enabled;

    public bool $is_api_enabled;

    protected string $dynamic_config_path = '/data/coolify/proxy/dynamic';

    protected Server $server;

    protected $rules = [
        'settings.fqdn' => 'nullable',
        'settings.resale_license' => 'nullable',
        'settings.public_port_min' => 'required',
        'settings.public_port_max' => 'required',
        'settings.custom_dns_servers' => 'nullable',
        'settings.instance_name' => 'nullable',
        'settings.allowed_ips' => 'nullable',
    ];

    protected $validationAttributes = [
        'settings.fqdn' => 'FQDN',
        'settings.resale_license' => 'Resale License',
        'settings.public_port_min' => 'Public port min',
        'settings.public_port_max' => 'Public port max',
        'settings.custom_dns_servers' => 'Custom DNS servers',
        'settings.allowed_ips' => 'Allowed IPs',
    ];

    public function mount()
    {
        $this->do_not_track = $this->settings->do_not_track;
        $this->is_auto_update_enabled = $this->settings->is_auto_update_enabled;
        $this->is_registration_enabled = $this->settings->is_registration_enabled;
        $this->is_dns_validation_enabled = $this->settings->is_dns_validation_enabled;
        $this->is_api_enabled = $this->settings->is_api_enabled;
    }

    public function instantSave()
    {
        $this->settings->do_not_track = $this->do_not_track;
        $this->settings->is_auto_update_enabled = $this->is_auto_update_enabled;
        $this->settings->is_registration_enabled = $this->is_registration_enabled;
        $this->settings->is_dns_validation_enabled = $this->is_dns_validation_enabled;
        $this->settings->is_api_enabled = $this->is_api_enabled;
        $this->settings->save();
        $this->dispatch('success', 'Settings updated!');
    }

    public function submit()
    {
        try {
            $error_show = false;
            $this->server = Server::findOrFail(0);
            $this->resetErrorBag();
            if ($this->settings->public_port_min > $this->settings->public_port_max) {
                $this->addError('settings.public_port_min', 'The minimum port must be lower than the maximum port.');

                return;
            }
            $this->validate();

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
