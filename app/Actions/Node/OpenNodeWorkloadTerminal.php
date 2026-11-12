<?php

namespace App\Actions\Node;

use App\Models\Node;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

/**
 * Opens a shell in a cluster application container with `workload.terminal.v1` (decision 0018).
 *
 * Flux asks Sentinel to start the shell on a PTY and returns a one-time ticket. The terminal server attaches
 * to the session with the ticket and relays it to the browser. Sentinel refuses containers that are not
 * workloads that Coolify manages.
 */
class OpenNodeWorkloadTerminal
{
    use AsAction;

    public const CAPABILITY = 'workload.terminal.v1';

    /**
     * @return array{url: string, ticket: string}
     */
    public function handle(Node $node, string $container, int $cols = 80, int $rows = 30): array
    {
        if ($node->supportsCapability(self::CAPABILITY) !== true) {
            throw new RuntimeException('Upgrade Sentinel on this server to open a terminal in its containers.');
        }
        $url = config('constants.flux.internal_url');
        $token = config('constants.flux.internal_token');
        if (! is_string($url) || $url === '' || ! is_string($token) || $token === '') {
            throw new RuntimeException('Flux is not configured, so the terminal cannot open.');
        }

        try {
            $response = Http::withToken($token)->acceptJson()->connectTimeout(10)->timeout(40)
                ->post(rtrim($url, '/').'/v1/terminals', [
                    'server_id' => $node->uuid,
                    'command_id' => (string) Str::uuid(),
                    'container' => $container,
                    'cols' => $cols,
                    'rows' => $rows,
                ]);
        } catch (ConnectionException) {
            throw new RuntimeException('Flux is not reachable, so the terminal cannot open.');
        }
        if ($response->failed()) {
            $reason = trim(mb_substr($response->body(), 0, 500));

            throw new RuntimeException(match ($response->status()) {
                404 => 'Sentinel on this server is not connected.',
                409 => 'Upgrade Sentinel on this server to open a terminal in its containers.',
                default => $reason === '' ? "Flux could not open the terminal (HTTP {$response->status()})." : $reason,
            });
        }
        $sessionId = $response->json('session_id');
        $ticket = $response->json('ticket');
        if (! is_string($sessionId) || preg_match('/^[a-f0-9-]{36}$/', $sessionId) !== 1 || ! is_string($ticket) || preg_match('/^[a-f0-9]{64}$/', $ticket) !== 1) {
            throw new RuntimeException('Flux returned an invalid terminal session.');
        }

        $websocketUrl = preg_replace('#^http#', 'ws', rtrim($url, '/'));

        return ['url' => "{$websocketUrl}/v1/terminals/{$sessionId}/attach", 'ticket' => $ticket];
    }
}
