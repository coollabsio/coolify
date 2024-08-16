<?php

namespace App\Livewire\Server;

use App\Actions\Server\StartSentinel;
use App\Actions\Server\StopSentinel;
use App\Jobs\PullSentinelImageJob;
use App\Models\Server;;

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

        // if ($this->server->settings->server_timezone === '') {
        //     $defaultTimezone = config('app.timezone');
        //     $this->updateServerTimezone($defaultTimezone);
        // }
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
            $this->dispatch('error', 'Server is not reachable.', 'Please validate your configuration and connection.<br><br>Check this <a target="_blank" class="underline" href="https://coolify.io/docs/knowledge-base/server/openssh">documentation</a> for further help. <br><br>Error: ' . $error);

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
        if (isCloud() && !isDev()) {
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

        $currentTimezone = $this->server->settings->getOriginal('server_timezone');
        $newTimezone = $this->server->settings->server_timezone;

        if ($currentTimezone !== $newTimezone || $currentTimezone === '') {
            try {
                $timezoneUpdated = $this->updateServerTimezone($newTimezone);
                if ($timezoneUpdated) {
                    $this->server->settings->server_timezone = $newTimezone;
                    $this->server->settings->save();
                } else {
                    return;
                }
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
        $this->updateServerTimezone($value);
    }

    public function updateServerTimezone($desired_timezone)
    {
        try {
            $commands = [
                "if command -v timedatectl > /dev/null 2>&1 && pidof systemd > /dev/null; then",
                "    timedatectl set-timezone " . escapeshellarg($desired_timezone),
                "elif [ -f /etc/timezone ]; then",
                "    echo " . escapeshellarg($desired_timezone) . " > /etc/timezone",
                "    rm -f /etc/localtime",
                "    ln -sf /usr/share/zoneinfo/" . escapeshellarg($desired_timezone) . " /etc/localtime",
                "elif [ -f /etc/localtime ]; then",
                "    rm -f /etc/localtime",
                "    ln -sf /usr/share/zoneinfo/" . escapeshellarg($desired_timezone) . " /etc/localtime",
                "else",
                "    echo 'Unable to set timezone'",
                "    exit 1",
                "fi",
                "if command -v dpkg-reconfigure > /dev/null 2>&1; then",
                "    dpkg-reconfigure -f noninteractive tzdata",
                "elif command -v tzdata-update > /dev/null 2>&1; then",
                "    tzdata-update",
                "elif [ -f /etc/sysconfig/clock ]; then",
                "    sed -i 's/^ZONE=.*/ZONE=\"" . $desired_timezone . "\"/' /etc/sysconfig/clock",
                "    source /etc/sysconfig/clock",
                "fi",
                "if command -v systemctl > /dev/null 2>&1 && pidof systemd > /dev/null; then",
                "    systemctl try-restart systemd-timesyncd.service || true",
                "elif command -v service > /dev/null 2>&1; then",
                "    service ntpd restart || service ntp restart || true",
                "fi",
                "echo \"Timezone updated to: $desired_timezone\"",
                "date"
            ];

            instant_remote_process($commands, $this->server);

            $verificationCommands = [
                "readlink /etc/localtime | sed 's#/usr/share/zoneinfo/##'",
                "date +'%Z %:z'"
            ];
            $verificationResult = instant_remote_process($verificationCommands, $this->server, false);
            $verificationLines = explode("\n", trim($verificationResult));

            if (count($verificationLines) !== 2) {
                $this->dispatch('error', 'Failed to verify timezone update. Unexpected server response.');
                return false;
            }

            $actualTimezone = trim($verificationLines[0]);
            [$abbreviation, $offset] = explode(' ', trim($verificationLines[1]));

            $desiredTz = new \DateTimeZone($desired_timezone);
            $desiredAbbr = (new \DateTime('now', $desiredTz))->format('T');
            $desiredOffset = $this->formatOffset($desiredTz->getOffset(new \DateTime('now', $desiredTz)));

            if ($actualTimezone === $desired_timezone && $abbreviation === $desiredAbbr && $offset === $desiredOffset) {
                $this->server->settings->server_timezone = $desired_timezone;
                $this->server->settings->save();
                return true;
            } else {
                $this->dispatch('error', 'Failed to update server timezone. The server reported a different timezone than requested.');
                return false;
            }
        } catch (\Exception $e) {
            $this->dispatch('error', 'Failed to update server timezone: ' . $e->getMessage());
            return false;
        }
    }

    private function formatOffset($offsetSeconds)
    {
        $hours = abs($offsetSeconds) / 3600;
        $minutes = (abs($offsetSeconds) % 3600) / 60;
        return sprintf('%s%02d:%02d', $offsetSeconds >= 0 ? '+' : '-', $hours, $minutes);
    }
}
