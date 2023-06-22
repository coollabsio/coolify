<?php

namespace App\Http\Livewire\Settings;

use App\Jobs\InstanceProxyCheckJob;
use App\Models\InstanceSettings as ModelsInstanceSettings;
use App\Models\Server;
use Livewire\Component;
use Spatie\Url\Url;
use Symfony\Component\Yaml\Yaml;

class Configuration extends Component
{
    public ModelsInstanceSettings $settings;
    public $do_not_track;
    public $is_auto_update_enabled;
    public $is_registration_enabled;
    protected string $dynamic_config_path = '/data/coolify/proxy/dynamic';
    protected Server $server;

    protected $rules = [
        'settings.fqdn' => 'nullable',
        'settings.wildcard_domain' => 'nullable',
        'settings.public_port_min' => 'required',
        'settings.public_port_max' => 'required',
        'settings.default_redirect_404' => 'nullable',
    ];
    protected $validationAttributes = [
        'settings.fqdn' => 'FQDN',
        'settings.wildcard_domain' => 'Wildcard domain',
        'settings.public_port_min' => 'Public port min',
        'settings.public_port_max' => 'Public port max',
        'settings.default_redirect_404' => 'Default redirect 404',
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
        $this->emit('success', 'Settings updated!');
    }
    private function setup_instance_fqdn()
    {
        $file = "$this->dynamic_config_path/coolify.yaml";
        if (empty($this->settings->fqdn)) {
            remote_process([
                "rm -f $file",
            ], $this->server);
        } else {
            $url = Url::fromString($this->settings->fqdn);
            $host = $url->getHost();
            $schema = $url->getScheme();
            $traefik_dynamic_conf = [
                'http' =>
                [
                    'routers' =>
                    [
                        'coolify-http' =>
                        [
                            'entryPoints' => [
                                0 => 'http',
                            ],
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

            if ($schema === 'https') {
                $traefik_dynamic_conf['http']['routers']['coolify-http']['middlewares'] = [
                    0 => 'redirect-to-https@docker',
                ];
                $traefik_dynamic_conf['http']['routers']['coolify-https'] = [
                    'entryPoints' => [
                        0 => 'https',
                    ],
                    'service' => 'coolify',
                    'rule' => "Host(`{$host}`)",
                    'tls' => [
                        'certresolver' => 'letsencrypt',
                    ],
                ];
            }
            $this->save_configuration_to_disk($traefik_dynamic_conf, $file);
        }
    }
    private function setup_default_redirect_404()
    {
        $file = "$this->dynamic_config_path/default_redirect_404.yaml";

        if (empty($this->settings->default_redirect_404)) {
            remote_process([
                "rm -f $file",
            ], $this->server);
        } else {
            $traefik_dynamic_conf = [
                'http' =>
                [
                    'routers' =>
                    [
                        'catchall' =>
                        [
                            'entryPoints' => [
                                0 => 'http',
                                1 => 'https',
                            ],
                            'service' => 'noop',
                            'rule' => "HostRegexp(`{catchall:.*}`)",
                            'priority' => 1,
                            'middlewares' => [
                                0 => 'redirect-regexp@file',
                            ],
                        ],
                    ],
                    'services' =>
                    [
                        'noop' =>
                        [
                            'loadBalancer' =>
                            [
                                'servers' =>
                                [
                                    0 =>
                                    [
                                        'url' => '',
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'middlewares' =>
                    [
                        'redirect-regexp' =>
                        [
                            'redirectRegex' =>
                            [
                                'regex' => '(.*)',
                                'replacement' => $this->settings->default_redirect_404,
                                'permanent' => false,
                            ],
                        ],
                    ],
                ],
            ];
            $this->save_configuration_to_disk($traefik_dynamic_conf, $file);
        }
    }
    private function save_configuration_to_disk(array $traefik_dynamic_conf, string $file)
    {
        $yaml = Yaml::dump($traefik_dynamic_conf, 12, 2);
        $yaml =
            "# This file is automatically generated by Coolify.\n" .
            "# Do not edit it manually (only if you know what are you doing).\n\n" .
            $yaml;

        $base64 = base64_encode($yaml);
        remote_process([
            "mkdir -p $this->dynamic_config_path",
            "echo '$base64' | base64 -d > $file",
        ], $this->server);

        if (config('app.env') == 'local') {
            ray($yaml);
        }
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

        $this->server = Server::findOrFail(0);
        $this->setup_instance_fqdn();
        setup_default_redirect_404(redirect_url: $this->settings->default_redirect_404, server: $this->server);
        if ($this->settings->fqdn || $this->settings->default_redirect_404) {
            dispatch(new InstanceProxyCheckJob());
        }
        $this->emit('success', 'Instance settings updated successfully!');
    }
}
