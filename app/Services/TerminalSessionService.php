<?php

namespace App\Services;

use App\Helpers\SshMultiplexingHelper;
use App\Models\Server;
use App\Models\User;
use App\Support\ValidationPatterns;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class TerminalSessionService
{
    private const TOKEN_TTL_SECONDS = 60;

    /**
     * @param  'shell'|'attach'  $mode  Container terminal mode: docker exec or docker attach.
     */
    public function issue(User $user, Server $server, ?string $container = null, string $mode = 'shell'): string
    {
        $token = Str::random(64);

        Cache::put($this->cacheKey($token), [
            'user_id' => $user->id,
            'team_id' => $user->currentTeam()->id,
            'server_uuid' => $server->uuid,
            'container' => $container,
            'mode' => $mode === 'attach' ? 'attach' : 'shell',
        ], now()->addSeconds(self::TOKEN_TTL_SECONDS));

        return $token;
    }

    public function redeem(User $user, string $token): string
    {
        $payload = Cache::lock($this->cacheKey($token).':lock', 5)
            ->get(fn () => Cache::pull($this->cacheKey($token)));

        if (! is_array($payload)
            || data_get($payload, 'user_id') !== $user->id
            || data_get($payload, 'team_id') !== $user->currentTeam()->id
            || ! is_string(data_get($payload, 'server_uuid'))
            || ! array_key_exists('container', $payload)) {
            throw new AccessDeniedHttpException('Invalid or expired terminal token.');
        }

        $server = Server::ownedByCurrentTeam()
            ->whereUuid(data_get($payload, 'server_uuid'))
            ->with('privateKey', 'settings')
            ->firstOrFail();

        if (! $server->isTerminalEnabled()
            || $server->isForceDisabled()
            || ! $server->privateKey
            || $server->privateKey->team_id !== $user->currentTeam()->id) {
            throw new AccessDeniedHttpException('Terminal target is not authorized.');
        }

        $command = $this->shellCommand();
        $container = $payload['container'];

        if ($container !== null) {
            if (! is_string($container)
                || ! ValidationPatterns::isValidContainerName($container)
                || getContainerStatus($server, $container) !== 'running') {
                throw new AccessDeniedHttpException('Terminal container is not authorized.');
            }

            $command = $this->containerCommand($server, $container, data_get($payload, 'mode') === 'attach' ? 'attach' : 'shell');
        }

        return SshMultiplexingHelper::generateSshCommand(
            $server,
            $command,
            commandTimeout: (int) config('constants.terminal.command_timeout')
        );
    }

    /**
     * Build the docker command that runs on the remote server for the given mode.
     *
     * @param  'shell'|'attach'  $mode
     */
    public function containerCommand(Server $server, string $container, string $mode): string
    {
        $escapedContainer = escapeshellarg($container);
        // Add sudo for non-root users to access the Docker socket.
        $sudo = $server->isNonRoot() ? 'sudo ' : '';

        if ($mode === 'attach') {
            $detachKeys = config('constants.terminal.detach_keys');
            $historyLines = (int) config('constants.terminal.console_history_lines');

            // Print recent output first so the console is not blank, then attach for live output.
            $primeHistory = $historyLines > 0
                ? "{$sudo}docker logs --tail {$historyLines} {$escapedContainer} 2>&1; "
                : '';

            // Attach to the container's main process. --detach-keys lets the user leave with
            // Ctrl-P, Ctrl-Q and --sig-proxy=false stops the client forwarding signals to the app.
            return "{$primeHistory}exec {$sudo}docker attach --detach-keys=\"{$detachKeys}\" --sig-proxy=false {$escapedContainer}";
        }

        return "{$sudo}docker exec -it {$escapedContainer} sh -c ".escapeshellarg($this->shellCommand());
    }

    private function shellCommand(): string
    {
        return 'PATH=$PATH:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin && '.
            'if [ -f ~/.profile ]; then . ~/.profile; fi && '.
            'if [ -n "$SHELL" ] && [ -x "$SHELL" ]; then exec $SHELL; else sh; fi';
    }

    private function cacheKey(string $token): string
    {
        return "terminal-session:{$token}";
    }
}
