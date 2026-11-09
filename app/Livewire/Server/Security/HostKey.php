<?php

namespace App\Livewire\Server\Security;

use App\Helpers\SshMultiplexingHelper;
use App\Jobs\ServerConnectionCheckJob;
use App\Models\Server;
use App\Models\ServerSetting;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;
use Livewire\Features\SupportEvents\Event;

class HostKey extends Component
{
    use AuthorizesRequests;

    public Server $server;

    public array $parameters = [];

    /** The dropdown value: "warn" or "enforce". */
    public string $checkMode = 'warn';

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

    /**
     * @param  string  $pendingKeyHash  SHA-256 of the pending key that the page showed, so that a key that changed later is not trusted.
     */
    public function trustPendingKey(string $pendingKeyHash, ?string $password): bool|string|Event
    {
        return $this->changeHostKey($password, 'trusted', function (ServerSetting $settings) use ($pendingKeyHash) {
            if (blank($settings->ssh_host_key_pending)) {
                throw new \RuntimeException('There is no new host key to trust.');
            }
            if (! hash_equals(hash('sha256', $settings->ssh_host_key_pending), $pendingKeyHash)) {
                throw new \RuntimeException('The server sent another new host key. Reload the page and compare the key again.');
            }
            $settings->forceFill([
                'ssh_host_key' => $settings->ssh_host_key_pending,
                'ssh_host_key_source' => 'manual',
                'ssh_host_key_trusted_at' => now(),
                'ssh_host_key_pending' => null,
            ]);
        });
    }

    public function resetHostKey(?string $password): bool|string|Event
    {
        return $this->changeHostKey($password, 'reset', fn (ServerSetting $settings) => $settings->forceFill([
            'ssh_host_key' => null,
            'ssh_host_key_source' => null,
            'ssh_host_key_trusted_at' => null,
            'ssh_host_key_pending' => null,
        ]));
    }

    /**
     * @param  bool  $disabled  The state that the button showed, so that a page open in another tab cannot turn the check back on.
     */
    public function setHostKeyCheckDisabled(bool $disabled, ?string $password): bool|string|Event
    {
        return $this->changeHostKey($password, $disabled ? 'check_disabled' : 'check_enabled', fn (ServerSetting $settings) => $settings->forceFill([
            'is_ssh_host_key_check_disabled' => $disabled,
            'is_ssh_host_key_check_enforced' => $disabled ? false : $settings->is_ssh_host_key_check_enforced,
        ]));
    }

    /**
     * Refuses a changed host key on this server ("enforce"), also when the instance only warns, or follows the instance
     * again ("warn"). Saved at once from the dropdown, without a password: warn still reports a changed key.
     * Refused while the check is disabled, so that a page open in another tab cannot turn the check back on without a password.
     */
    public function setHostKeyCheckMode(string $mode): bool|string|Event
    {
        if (! in_array($mode, ['warn', 'enforce'], true)) {
            return false;
        }
        $enforced = $mode === 'enforce';

        return $this->changeHostKey(null, $enforced ? 'check_enforced' : 'check_not_enforced', function (ServerSetting $settings) use ($enforced) {
            if ($settings->is_ssh_host_key_check_disabled) {
                throw new \RuntimeException('The host key check is disabled for this server. Reload the page and enable the check first.');
            }
            $settings->forceFill(['is_ssh_host_key_check_enforced' => $enforced]);
        }, confirmPassword: false);
    }

    /**
     * @param  callable(ServerSetting): mixed  $change
     */
    private function changeHostKey(?string $password, string $action, callable $change, bool $confirmPassword = true): bool|string|Event
    {
        try {
            $this->authorize('update', $this->server);
            if (! $this->server->refresh()->canBeValidated()) {
                throw new \RuntimeException('This server was transferred to another Coolify instance.');
            }
            if ($confirmPassword && ! verifyPasswordConfirmation($password, $this)) {
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

            SshMultiplexingHelper::removeMuxFileAfterHostKeyChange($this->server);
            // The check can take up to a minute on a slow server, so it runs in the queue and the page polls for the result.
            ServerConnectionCheckJob::dispatch($this->server);
            $this->dispatch('hostKeySettingsChanged')->to(Index::class);
            $this->dispatch('success', 'The host key settings were saved. Coolify now checks the connection again.');

            return true;
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render(): View
    {
        $settings = $this->server->settings;
        $this->checkMode = $settings->is_ssh_host_key_check_enforced ? 'enforce' : 'warn';

        return view('livewire.server.security.host-key', [
            'mode' => SshMultiplexingHelper::hostKeyCheckingMode($this->server),
            'instanceMode' => config('constants.ssh.host_key_checking'),
            'fingerprints' => ServerSetting::sshHostKeyFingerprints($settings->ssh_host_key),
            'pendingFingerprints' => ServerSetting::sshHostKeyFingerprints($settings->ssh_host_key_pending),
        ]);
    }
}
