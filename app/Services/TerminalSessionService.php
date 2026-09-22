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

    public function issue(User $user, Server $server, ?string $container = null): string
    {
        $token = Str::random(64);

        Cache::put($this->cacheKey($token), [
            'user_id' => $user->id,
            'team_id' => $user->currentTeam()->id,
            'server_uuid' => $server->uuid,
            'container' => $container,
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

            $dockerCommand = 'docker exec -it '.escapeshellarg($container).' sh -c '.escapeshellarg($command);
            $command = $server->isNonRoot() ? "sudo {$dockerCommand}" : $dockerCommand;
        }

        return SshMultiplexingHelper::generateSshCommand(
            $server,
            $command,
            commandTimeout: (int) config('constants.terminal.command_timeout')
        );
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
