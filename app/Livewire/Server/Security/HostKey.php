<?php

namespace App\Livewire\Server\Security;

use App\Helpers\SshMultiplexingHelper;
use App\Jobs\ServerConnectionCheckJob;
use App\Models\Server;
use App\Models\ServerSetting;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class HostKey extends Component
{
    use AuthorizesRequests;

    public Server $server;

    public array $parameters = [];

    public function mount(string $server_uuid)
    {
        try {
            $this->server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();
            $this->authorize('update', $this->server);
            $this->parameters = get_route_parameters();
        } catch (\Throwable) {
            return redirect()->route('server.index');
        }
    }

    public function trustPendingKey($password)
    {
        return $this->changeHostKey($password, 'trusted', function (ServerSetting $settings) {
            if (blank($settings->ssh_host_key_pending)) {
                throw new \RuntimeException('There is no new host key to trust.');
            }
            $settings->forceFill([
                'ssh_host_key' => $settings->ssh_host_key_pending,
                'ssh_host_key_source' => 'manual',
                'ssh_host_key_trusted_at' => now(),
                'ssh_host_key_pending' => null,
            ]);
        });
    }

    public function resetHostKey($password)
    {
        return $this->changeHostKey($password, 'reset', fn (ServerSetting $settings) => $settings->forceFill([
            'ssh_host_key' => null,
            'ssh_host_key_source' => null,
            'ssh_host_key_trusted_at' => null,
            'ssh_host_key_pending' => null,
        ]));
    }

    public function toggleHostKeyCheck($password)
    {
        $action = $this->server->settings->is_ssh_host_key_check_disabled ? 'check_enabled' : 'check_disabled';

        return $this->changeHostKey($password, $action, fn (ServerSetting $settings) => $settings->forceFill([
            'is_ssh_host_key_check_disabled' => ! $settings->is_ssh_host_key_check_disabled,
        ]));
    }

    /**
     * @param  callable(ServerSetting): void  $change
     */
    private function changeHostKey($password, string $action, callable $change)
    {
        try {
            $this->authorize('update', $this->server);
            if (! verifyPasswordConfirmation($password, $this)) {
                return 'The provided password is incorrect.';
            }

            $settings = $this->server->settings->refresh();
            $previousFingerprints = ServerSetting::sshHostKeyFingerprints($settings->ssh_host_key);
            $change($settings);
            $settings->save();

            auditLog("ui.server.ssh_host_key.{$action}", [
                'team_id' => $this->server->team_id,
                'server_uuid' => $this->server->uuid,
                'server_name' => $this->server->name,
                'previous_fingerprints' => $previousFingerprints,
                'fingerprints' => ServerSetting::sshHostKeyFingerprints($settings->ssh_host_key),
            ]);

            SshMultiplexingHelper::removeMuxFile($this->server);
            ServerConnectionCheckJob::dispatch($this->server);
            $this->dispatch('success', 'The host key settings were saved. Coolify checks the connection again.');

            return true;
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        $settings = $this->server->settings;

        return view('livewire.server.security.host-key', [
            'mode' => SshMultiplexingHelper::hostKeyCheckingMode($this->server),
            'fingerprints' => ServerSetting::sshHostKeyFingerprints($settings->ssh_host_key),
            'pendingFingerprints' => ServerSetting::sshHostKeyFingerprints($settings->ssh_host_key_pending),
        ]);
    }
}
