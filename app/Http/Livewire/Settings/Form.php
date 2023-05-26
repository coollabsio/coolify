<?php

namespace App\Http\Livewire\Settings;

use App\Enums\ActivityTypes;
use App\Models\InstanceSettings as ModelsInstanceSettings;
use App\Models\Server;
use Livewire\Component;
use Spatie\Url\Url;
use Symfony\Component\Yaml\Yaml;

class Form extends Component
{
    public ModelsInstanceSettings $settings;
    public $do_not_track;
    public $is_auto_update_enabled;
    public $is_registration_enabled;

    protected $rules = [
        'settings.fqdn' => 'nullable',
        'settings.wildcard_domain' => 'nullable',
        'settings.public_port_min' => 'required',
        'settings.public_port_max' => 'required',
    ];
    public function mount()
    {
        $this->do_not_track = $this->settings->do_not_track;
        $this->is_auto_update_enabled = $this->settings->is_auto_update_enabled;
        $this->is_registration_enabled = $this->settings->is_registration_enabled;
    }
    public function instantSave()
    {
        $this->settings->do_not_track = $this->do_not_track;
        $this->settings->is_auto_update_enabled = $this->is_auto_update_enabled;
        $this->settings->is_registration_enabled = $this->is_registration_enabled;
        $this->settings->save();
        $this->emit('saved', 'Settings updated!');
    }
    public function submit()
    {
        $this->resetErrorBag();
        if ($this->settings->public_port_min > $this->settings->public_port_max) {
            $this->addError('settings.public_port_min', 'The minimum port must be lower than the maximum port.');
            return;
        }
        $this->validate();
        $this->settings->save();

        $dynamic_config_path = '/data/coolify/proxy/dynamic';
        if (config('app.env') == 'local') {
            $server = Server::findOrFail(1);
        } else {
            $server = Server::findOrFail(0);
        }

        if (empty($this->settings->fqdn)) {
            remote_process([
                "rm -f $dynamic_config_path/coolify.yaml",
            ], $server);
        } else {
            $url = Url::fromString($this->settings->fqdn);
            $host = $url->getHost();
            $schema = $url->getScheme();
            $traefik_dynamic_conf = [
                'http' =>
                [
                    'routers' =>
                    [
                        'coolify' =>
                        [
                            'service' => 'coolify',
                            'rule' => "Host(`{$host}`)",
                        ],
                    ],
                    'services' =>
                    [
                        'coolify' =>
                        [
                            'loadBalancer' =>
                            [
                                'servers' =>
                                [
                                    0 =>
                                    [
                                        'url' => 'http://coolify:80',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ];
            $traefik_dynamic_conf['http']['routers']['coolify']['entryPoints'] = [
                0 => 'http',
            ];
            if ($schema === 'https') {
                $traefik_dynamic_conf['http']['routers']['coolify']['entryPoints'][] = 'https';
                $traefik_dynamic_conf['http']['routers']['coolify']['tls'] = [
                    'certresolver' => 'letsencrypt',
                ];
                $traefik_dynamic_conf['http']['routers']['coolify']['middlewares'] = [
                    0 => 'redirect-to-https@docker',
                ];
            } else {
                $traefik_dynamic_conf['http']['routers']['coolify']['entryPoints'] = [
                    0 => 'http',
                ];
            }
            $yaml = Yaml::dump($traefik_dynamic_conf);
            if (config('app.env') == 'local') {
                dump($yaml);
                return;
            }
            $base64 = base64_encode($yaml);
            remote_process([
                "mkdir -p $dynamic_config_path",
                "echo '$base64' | base64 -d > $dynamic_config_path/coolify.yaml",
            ], $server);
        }
    }
}
