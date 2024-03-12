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
    public bool $next_channel;
    protected string $dynamic_config_path = '/data/coolify/proxy/dynamic';
    protected Server $server;

    protected $rules = [
        'settings.fqdn' => 'nullable',
        'settings.resale_license' => 'nullable',
        'settings.public_port_min' => 'required',
        'settings.public_port_max' => 'required',
        'settings.custom_dns_servers' => 'nullable',
    ];
    protected $validationAttributes = [
        'settings.fqdn' => 'FQDN',
        'settings.resale_license' => 'Resale License',
        'settings.public_port_min' => 'Public port min',
        'settings.public_port_max' => 'Public port max',
        'settings.custom_dns_servers' => 'Custom DNS servers',
    ];

    public function mount()
    {
        $this->do_not_track = $this->settings->do_not_track;
        $this->is_auto_update_enabled = $this->settings->is_auto_update_enabled;
        $this->is_registration_enabled = $this->settings->is_registration_enabled;
        $this->next_channel = $this->settings->next_channel;
        $this->is_dns_validation_enabled = $this->settings->is_dns_validation_enabled;
    }

    public function instantSave()
    {
        $this->settings->do_not_track = $this->do_not_track;
        $this->settings->is_auto_update_enabled = $this->is_auto_update_enabled;
        $this->settings->is_registration_enabled = $this->is_registration_enabled;
        $this->settings->is_dns_validation_enabled = $this->is_dns_validation_enabled;
        if ($this->next_channel) {
            $this->settings->next_channel = false;
            $this->next_channel = false;
        } else {
            $this->settings->next_channel = $this->next_channel;
        }
        $this->settings->save();
        $this->dispatch('success', 'Settings updated!');
    }

    public function submit()
    {
        $this->resetErrorBag();
        if ($this->settings->public_port_min > $this->settings->public_port_max) {
            $this->addError('settings.public_port_min', 'The minimum port must be lower than the maximum port.');
            return;
        }
        $this->validate();

        $this->settings->custom_dns_servers = str($this->settings->custom_dns_servers)->replaceEnd(',', '')->trim();
        $this->settings->custom_dns_servers = str($this->settings->custom_dns_servers)->trim()->explode(',')->map(function ($dns) {
            return str($dns)->trim()->lower();
        });
        $this->settings->custom_dns_servers = $this->settings->custom_dns_servers->unique();
        $this->settings->custom_dns_servers = $this->settings->custom_dns_servers->implode(',');

        $this->settings->save();
        $this->server = Server::findOrFail(0);
        $this->server->setupDynamicProxyConfiguration();
        $this->dispatch('success', 'Instance settings updated successfully!');
    }
}
