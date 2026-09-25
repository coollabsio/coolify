<?php

namespace App\Services;

use App\Helpers\SshMultiplexingHelper;
use App\Models\Node;
use App\Models\Server;
use App\Models\User;
use App\Support\ValidationPatterns;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class TerminalSessionService
{
    private const TOKEN_TTL_SECONDS = 60;

    public function issue(User $user, Server|Node $server, ?string $container = null): string
    {
        if ($server instanceof Node && $container !== null) {
            throw new AccessDeniedHttpException('Container terminals are not available for Nodes.');
        }
        $token = Str::random(64);

        Cache::put($this->cacheKey($token), [
            'user_id' => $user->id,
            'team_id' => $user->currentTeam()->id,
            'server_uuid' => $server->uuid,
            'target_type' => $server instanceof Node ? 'node' : 'server',
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

        if (data_get($payload, 'target_type') === 'node') {
            return $this->redeemNode($user, $payload);
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

    /** @param array{server_uuid: string, container: ?string} $payload */
    private function redeemNode(User $user, array $payload): string
    {
        if (! isDev() || ! config('constants.sentinel.host_enabled', false) || $payload['container'] !== null) {
            throw new AccessDeniedHttpException('Terminal target is not authorized.');
        }

        $node = Node::query()
            ->where('team_id', $user->currentTeam()->id)
            ->where('uuid', $payload['server_uuid'])
            ->with('privateKey')
            ->firstOrFail();

        if (! $user->can('view', $node)
            || ! $node->is_reachable
            || ! $node->is_usable
            || ! $node->privateKey
            || $node->privateKey->team_id !== $user->currentTeam()->id) {
            throw new AccessDeniedHttpException('Terminal target is not authorized.');
        }

        return SshMultiplexingHelper::generateSshCommand(
            $node,
            $this->shellCommand(),
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
