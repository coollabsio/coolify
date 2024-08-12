<?php

namespace App\Livewire\Server;

use App\Actions\Server\StartSentinel;
use App\Actions\Server\StopSentinel;
use App\Jobs\PullSentinelImageJob;
use App\Models\Server;
use DateTimeZone;
use Livewire\Component;

class Form extends Component
{
    public Server $server;

    public bool $isValidConnection = false;

    public bool $isValidDocker = false;

    public ?string $wildcard_domain = null;

    public int $cleanup_after_percentage;

    public bool $dockerInstallationStarted = false;

    public bool $revalidate = false;

    protected $listeners = ['serverInstalled', 'revalidate' => '$refresh'];

    protected $rules = [
        'server.name' => 'required',
        'server.description' => 'nullable',
        'server.ip' => 'required',
        'server.user' => 'required',
        'server.port' => 'required',
        'server.settings.is_cloudflare_tunnel' => 'required|boolean',
        'server.settings.is_reachable' => 'required',
        'server.settings.is_swarm_manager' => 'required|boolean',
        'server.settings.is_swarm_worker' => 'required|boolean',
        'server.settings.is_build_server' => 'required|boolean',
        'server.settings.is_force_cleanup_enabled' => 'required|boolean',
        'server.settings.concurrent_builds' => 'required|integer|min:1',
        'server.settings.dynamic_timeout' => 'required|integer|min:1',
        'server.settings.is_metrics_enabled' => 'required|boolean',
        'server.settings.metrics_token' => 'required',
        'server.settings.metrics_refresh_rate_seconds' => 'required|integer|min:1',
        'server.settings.metrics_history_days' => 'required|integer|min:1',
        'wildcard_domain' => 'nullable|url',
        'server.settings.is_server_api_enabled' => 'required|boolean',
        'server.settings.server_timezone' => 'required|string|timezone',
    ];

    protected $validationAttributes = [
        'server.name' => 'Name',
        'server.description' => 'Description',
        'server.ip' => 'IP address/Domain',
        'server.user' => 'User',
        'server.port' => 'Port',
        'server.settings.is_cloudflare_tunnel' => 'Cloudflare Tunnel',
        'server.settings.is_reachable' => 'Is reachable',
        'server.settings.is_swarm_manager' => 'Swarm Manager',
        'server.settings.is_swarm_worker' => 'Swarm Worker',
        'server.settings.is_build_server' => 'Build Server',
        'server.settings.concurrent_builds' => 'Concurrent Builds',
        'server.settings.dynamic_timeout' => 'Dynamic Timeout',
        'server.settings.is_metrics_enabled' => 'Metrics',
        'server.settings.metrics_token' => 'Metrics Token',
        'server.settings.metrics_refresh_rate_seconds' => 'Metrics Interval',
        'server.settings.metrics_history_days' => 'Metrics History',
        'server.settings.is_server_api_enabled' => 'Server API',
        'server.settings.server_timezone' => 'Server Timezone',
    ];

    public $timezones;

    public function mount(Server $server)
    {
        $this->server = $server;
        $this->timezones = collect(timezone_identifiers_list())->sort()->values()->toArray();
        $this->wildcard_domain = $this->server->settings->wildcard_domain;
        $this->cleanup_after_percentage = $this->server->settings->cleanup_after_percentage;
    }

    public function serverInstalled()
    {
        $this->server->refresh();
        $this->server->settings->refresh();
    }

    public function updatedServerSettingsIsBuildServer()
    {
        $this->dispatch('refreshServerShow');
        $this->dispatch('serverRefresh');
        $this->dispatch('proxyStatusUpdated');
    }

    public function checkPortForServerApi()
    {
        try {
            if ($this->server->settings->is_server_api_enabled === true) {
                $this->server->checkServerApi();
                $this->dispatch('success', 'Server API is reachable.');
            }
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function instantSave()
    {
        try {
            refresh_server_connection($this->server->privateKey);
            $this->validateServer(false);
            $this->server->settings->save();
            $this->server->save();
            $this->dispatch('success', 'Server updated.');
            $this->dispatch('refreshServerShow');
            if ($this->server->isSentinelEnabled()) {
                PullSentinelImageJob::dispatchSync($this->server);
                ray('Sentinel is enabled');
                if ($this->server->settings->isDirty('is_metrics_enabled')) {
                    $this->dispatch('reloadWindow');
                }
                if ($this->server->settings->isDirty('is_server_api_enabled') && $this->server->settings->is_server_api_enabled === true) {
                    ray('Starting sentinel');

                }
            } else {
                ray('Sentinel is not enabled');
                StopSentinel::dispatch($this->server);
            }
            // $this->checkPortForServerApi();

        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function restartSentinel()
    {
        try {
            $version = get_latest_sentinel_version();
            StartSentinel::run($this->server, $version, true);
            $this->dispatch('success', 'Sentinel restarted.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function revalidate()
    {
        $this->revalidate = true;
    }

    public function checkLocalhostConnection()
    {
        $this->submit();
        ['uptime' => $uptime, 'error' => $error] = $this->server->validateConnection();
        if ($uptime) {
            $this->dispatch('success', 'Server is reachable.');
            $this->server->settings->is_reachable = true;
            $this->server->settings->is_usable = true;
            $this->server->settings->save();
            $this->dispatch('proxyStatusUpdated');
        } else {
            $this->dispatch('error', 'Server is not reachable.', 'Please validate your configuration and connection.<br><br>Check this <a target="_blank" class="underline" href="https://coolify.io/docs/knowledge-base/server/openssh">documentation</a> for further help. <br><br>Error: '.$error);

            return;
        }
    }

    public function validateServer($install = true)
    {
        $this->server->update([
            'validation_logs' => null,
        ]);
        $this->dispatch('init', $install);
    }

    public function submit()
    {
        if (isCloud() && ! isDev()) {
            $this->validate();
            $this->validate([
                'server.ip' => 'required',
            ]);
        } else {
            $this->validate();
        }
        $uniqueIPs = Server::all()->reject(function (Server $server) {
            return $server->id === $this->server->id;
        })->pluck('ip')->toArray();
        if (in_array($this->server->ip, $uniqueIPs)) {
            $this->dispatch('error', 'IP address is already in use by another team.');

            return;
        }
        refresh_server_connection($this->server->privateKey);
        $this->server->settings->wildcard_domain = $this->wildcard_domain;
        $this->server->settings->cleanup_after_percentage = $this->cleanup_after_percentage;

        // Update the timezone if it has changed
        if ($this->server->settings->isDirty('server_timezone')) {
            try {
                $message = $this->updateServerTimezone($this->server->settings->server_timezone);
                $this->dispatch('success', $message);
            } catch (\Exception $e) {
                $this->dispatch('error', 'Failed to update server timezone: ' . $e->getMessage());
                return;
            }
        }

        $this->server->settings->save();
        $this->server->save();
        $this->dispatch('success', 'Server updated.');
    }

    public function updatedServerTimezone($value)
    {
        if (!is_string($value) || !in_array($value, timezone_identifiers_list())) {
            $this->addError('server.settings.server_timezone', 'Invalid timezone.');
            return;
        }
        $this->server->settings->server_timezone = $value;
    }

    private function updateServerTimezone($value)
    {
        $commands = [
            "date +%Z",
            "if command -v timedatectl >/dev/null 2>&1; then timedatectl set-timezone " . escapeshellarg($value) . " 2>&1 || echo 'timedatectl failed'; fi",
            "if [ -f /etc/timezone ]; then echo " . escapeshellarg($value) . " > /etc/timezone && dpkg-reconfigure -f noninteractive tzdata 2>&1 || echo '/etc/timezone update failed'; fi",
            "if [ -L /etc/localtime ]; then ln -sf /usr/share/zoneinfo/" . escapeshellarg($value) . " /etc/localtime 2>&1 || echo '/etc/localtime update failed'; fi",
            "date +%Z",
        ];

        $result = instant_remote_process($commands, $this->server);
        
        if (!isset($result['output']) || empty($result['output'])) {
            throw new \Exception("No output received from server. The timezone may not have been updated.");
        }

        $output = is_array($result['output']) ? $result['output'] : explode("\n", trim($result['output']));
        $output = array_filter($output); // Remove empty lines

        if (count($output) < 2) {
            throw new \Exception("Unexpected output format: " . implode("\n", $output));
        }

        $oldTimezone = $output[0];
        $newTimezone = end($output);

        $errors = array_filter($output, function($line) {
            return strpos($line, 'failed') !== false;
        });

        if (!empty($errors)) {
            throw new \Exception("Timezone change failed. Errors: " . implode(", ", $errors));
        }

        if ($oldTimezone === $newTimezone) {
            throw new \Exception("Timezone change failed. The timezone is still set to {$oldTimezone}.");
        }

        try {
            $dateTime = new \DateTime('now', new \DateTimeZone($value));
            $phpTimezone = $dateTime->getTimezone()->getName();
        } catch (\Exception $e) {
            throw new \Exception("Invalid PHP timezone: {$value}. Error: " . $e->getMessage());
        }

        $this->server->settings->server_timezone = $phpTimezone;
        $this->server->settings->save();

        return "Timezone successfully changed from {$oldTimezone} to {$newTimezone} (PHP: {$phpTimezone}).";
    }
}