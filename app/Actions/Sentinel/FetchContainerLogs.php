<?php

namespace App\Actions\Sentinel;

use App\Models\Node;
use App\Models\NodeWorkload;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

/**
 * Reads recent logs of a cluster application container through Flux (`container.logs.v1`).
 *
 * The request is read-only and polled, so it creates no node operation, like `logs.read.v1`.
 * Logs can contain secrets: they are neither logged nor cached.
 */
class FetchContainerLogs
{
    use AsAction;

    public const CAPABILITY = 'container.logs.v1';

    public const MAX_LINES = 10000;

    public const CAPABILITY_MISSING_MESSAGE = 'Upgrade Sentinel on this server to view logs.';

    /**
     * @return array{logs: string, truncated: bool, observed_at_unix_ms: int}
     */
    public function handle(Node $node, NodeWorkload $workload, int $lines, ?int $sinceUnixSeconds = null): array
    {
        if ($lines < 1 || $lines > self::MAX_LINES) {
            throw new InvalidArgumentException('The number of log lines must be between 1 and '.self::MAX_LINES.'.');
        }
        if ($node->team_id !== $workload->team_id || ! $workload->nodes()->whereKey($node->id)->exists()) {
            throw new RuntimeException('The application is not placed on this server.');
        }
        if ($node->supportsCapability(self::CAPABILITY) === false) {
            throw new RuntimeException(self::CAPABILITY_MISSING_MESSAGE);
        }
        $url = config('constants.flux.internal_url');
        $token = config('constants.flux.internal_token');
        if (! is_string($url) || $url === '' || ! is_string($token) || $token === '') {
            throw new RuntimeException('Flux is not configured, so logs cannot be read.');
        }

        $name = self::containerName($workload);
        $payload = [
            'server_id' => $node->uuid,
            'command_id' => (string) Str::uuid(),
            'name' => $name,
            'lines' => $lines,
        ];
        if ($sinceUnixSeconds !== null) {
            $payload['since_unix_seconds'] = $sinceUnixSeconds;
        }

        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->timeout(15)
                ->post(rtrim($url, '/').'/v1/commands/container.logs', $payload);
        } catch (ConnectionException) {
            throw new RuntimeException('Flux is not reachable, so logs cannot be read.');
        }

        if ($response->status() === 409) {
            throw new RuntimeException(self::CAPABILITY_MISSING_MESSAGE);
        }
        if ($response->status() === 404) {
            throw new RuntimeException('Sentinel on this server is not connected.');
        }
        if ($response->failed()) {
            $message = data_get($response->json(), 'message') ?? data_get($response->json(), 'error');
            $reason = is_string($message) && trim($message) !== ''
                ? ': '.FetchNodeLogs::redact(mb_substr(trim($message), 0, 500))
                : '.';

            throw new RuntimeException(match ($response->status()) {
                422 => 'Flux rejected the log request'.$reason,
                502 => 'Sentinel could not read the logs'.$reason,
                default => "Flux could not read the logs (HTTP {$response->status()}){$reason}",
            });
        }

        $body = $response->json();
        $validator = Validator::make(is_array($body) ? $body : [], [
            'command_id' => ['required', 'string'],
            'observed_at_unix_ms' => ['required', 'integer'],
            'name' => ['required', 'string', 'in:'.$name],
            'logs' => ['present', 'nullable', 'string'],
            'truncated' => ['required', 'boolean'],
        ]);
        if ($validator->fails()) {
            throw new RuntimeException('Flux returned an invalid log response.');
        }
        $data = $validator->validated();

        return [
            'logs' => (string) ($data['logs'] ?? ''),
            'truncated' => (bool) $data['truncated'],
            'observed_at_unix_ms' => (int) $data['observed_at_unix_ms'],
        ];
    }

    public static function containerName(NodeWorkload $workload): string
    {
        return 'coolify-'.$workload->uuid.'-main';
    }
}
