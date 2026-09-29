<?php

namespace App\Actions\Sentinel;

use App\Models\Node;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

/**
 * Read recent Node service logs on demand.
 *
 * Flux (`logs.read.v1`) is the normal transport. SSH + journald is used when
 * the Node cannot answer through Flux (not connected, no capability, Flux
 * unreachable) and always for the `journal` source.
 */
class FetchNodeLogs
{
    use AsAction;

    public const DEFAULT_LIMIT = 200;

    public const MAX_LIMIT = 500;

    public const CAPABILITY = 'logs.read.v1';

    /** @var array<string, string> */
    public const SOURCES = [
        'sentinel' => 'Sentinel',
        'corrosion' => 'Corrosion',
        'discovery_dns' => 'Discovery DNS',
        'journal' => 'System journal (SSH)',
    ];

    /** @var array<string, string> */
    public const JOURNAL_UNITS = [
        'sentinel' => 'sentinel.service',
        'corrosion' => 'corrosion.service',
        'discovery_dns' => 'coolify-discovery-dns.service',
        'journal' => 'sentinel.service',
    ];

    private const LEVELS = ['error', 'warn', 'info', 'debug', 'trace'];

    /**
     * @return array{transport: 'flux'|'ssh', source: string, truncated: bool, events: list<array{timestamp_unix_ms: int, level: string, component: string, message: string, fields: array<string, string>}>}
     */
    public function handle(Node $node, string $source, int $limit = self::DEFAULT_LIMIT): array
    {
        if (! array_key_exists($source, self::SOURCES)) {
            throw new InvalidArgumentException('Unknown log source.');
        }
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new InvalidArgumentException('The log limit must be between 1 and '.self::MAX_LIMIT.'.');
        }

        if ($source !== 'journal') {
            $result = $this->readThroughFlux($node, $source, $limit);
            if ($result !== null) {
                return $result;
            }
        }

        return $this->readThroughSsh($node, $source, $limit);
    }

    public static function journalCommand(string $source, int $limit): string
    {
        $unit = self::JOURNAL_UNITS[$source] ?? throw new InvalidArgumentException('Unknown log source.');
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new InvalidArgumentException('The log limit must be between 1 and '.self::MAX_LIMIT.'.');
        }

        return 'journalctl --unit '.escapeshellarg($unit).' --no-pager --output json --lines '.$limit;
    }

    /**
     * Parse `journalctl --output json` output into normalized events (oldest first).
     *
     * @return list<array{timestamp_unix_ms: int, level: string, component: string, message: string, fields: array<string, string>}>
     */
    public static function parseJournal(?string $output, string $unit): array
    {
        $component = str($unit)->beforeLast('.service')->toString();
        $events = [];
        foreach (preg_split('/\R/', trim((string) $output)) ?: [] as $line) {
            $entry = json_decode(trim($line), true);
            if (! is_array($entry)) {
                continue;
            }
            $timestamp = $entry['__REALTIME_TIMESTAMP'] ?? null;
            if (! is_numeric($timestamp)) {
                continue;
            }
            $message = self::journalMessage($entry['MESSAGE'] ?? null);
            if ($message === null) {
                continue;
            }
            $fields = [];
            if (isset($entry['_PID']) && is_scalar($entry['_PID'])) {
                $fields['pid'] = (string) $entry['_PID'];
            }

            $events[] = [
                'timestamp_unix_ms' => intdiv((int) $timestamp, 1000),
                'level' => self::journalLevel($entry['PRIORITY'] ?? null),
                'component' => $component,
                'message' => self::redact(preg_replace('/\e\[[0-9;]*m/', '', $message) ?? $message),
                'fields' => $fields,
            ];
        }

        return $events;
    }

    public static function redact(string $text): string
    {
        $text = preg_replace('/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/=-]+/i', '$1 [redacted]', $text) ?? $text;
        $text = preg_replace('/\beyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]*)?/', '[redacted]', $text) ?? $text;

        return preg_replace(
            '/\b([A-Za-z0-9_-]*(?:token|password|passwd|secret|api[_-]?key)["\']?\s*[=:]\s*["\']?)(?!\[redacted\])[^\s"\'&,;]+/i',
            '$1[redacted]',
            $text,
        ) ?? $text;
    }

    /**
     * @return array{transport: 'flux', source: string, truncated: bool, events: list<array{timestamp_unix_ms: int, level: string, component: string, message: string, fields: array<string, string>}>}|null
     */
    private function readThroughFlux(Node $node, string $source, int $limit): ?array
    {
        $url = config('constants.flux.internal_url');
        $token = config('constants.flux.internal_token');
        if ($node->supportsCapability(self::CAPABILITY) === false
            || ! is_string($url) || $url === '' || ! is_string($token) || $token === '') {
            return null;
        }

        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->timeout(12)
                ->post(rtrim($url, '/').'/v1/commands/logs.read', [
                    'server_id' => $node->uuid,
                    'source' => $source,
                    'limit' => $limit,
                ]);
        } catch (ConnectionException) {
            return null;
        }

        if (in_array($response->status(), [404, 409], true)) {
            return null;
        }
        if ($response->failed()) {
            throw new RuntimeException("Flux could not read {$this->label($source)} logs (HTTP {$response->status()}).");
        }

        $payload = $response->json();
        $validator = Validator::make(is_array($payload) ? $payload : [], [
            'command_id' => ['required', 'string'],
            'observed_at_unix_ms' => ['required', 'integer'],
            'source' => ['required', 'string', 'in:'.$source],
            'truncated' => ['required', 'boolean'],
            'events' => ['present', 'array', 'max:'.self::MAX_LIMIT],
            'events.*.timestamp_unix_ms' => ['required', 'integer', 'min:0'],
            'events.*.level' => ['required', 'string', 'in:'.implode(',', self::LEVELS)],
            'events.*.component' => ['present', 'string', 'max:255'],
            'events.*.message' => ['present', 'string'],
            'events.*.fields' => ['present', 'array'],
            'events.*.fields.*' => ['string'],
        ]);
        if ($validator->fails()) {
            throw new RuntimeException('Flux returned an invalid log response.');
        }
        $data = $validator->validated();

        return [
            'transport' => 'flux',
            'source' => $source,
            'truncated' => (bool) $data['truncated'],
            'events' => array_values(array_map(fn (array $event): array => [
                'timestamp_unix_ms' => (int) $event['timestamp_unix_ms'],
                'level' => $event['level'],
                'component' => (string) $event['component'],
                'message' => self::redact((string) $event['message']),
                'fields' => array_map(fn (string $value): string => self::redact($value), $event['fields'] ?? []),
            ], $data['events'])),
        ];
    }

    /**
     * @return array{transport: 'ssh', source: string, truncated: bool, events: list<array{timestamp_unix_ms: int, level: string, component: string, message: string, fields: array<string, string>}>}
     */
    private function readThroughSsh(Node $node, string $source, int $limit): array
    {
        $output = instant_remote_process(
            [self::journalCommand($source, $limit)],
            $node,
            timeout: 30,
            disableMultiplexing: true,
        );
        $events = self::parseJournal($output, self::JOURNAL_UNITS[$source]);

        return [
            'transport' => 'ssh',
            'source' => $source,
            'truncated' => count($events) >= $limit,
            'events' => $events,
        ];
    }

    private function label(string $source): string
    {
        return self::SOURCES[$source];
    }

    private static function journalMessage(mixed $message): ?string
    {
        if (is_string($message)) {
            return $message;
        }
        if (is_array($message) && array_is_list($message)) {
            $bytes = '';
            foreach ($message as $byte) {
                if (! is_int($byte) || $byte < 0 || $byte > 255) {
                    return null;
                }
                $bytes .= chr($byte);
            }

            return mb_convert_encoding($bytes, 'UTF-8', 'UTF-8');
        }

        return null;
    }

    private static function journalLevel(mixed $priority): string
    {
        if (! is_numeric($priority)) {
            return 'info';
        }

        return match (true) {
            (int) $priority <= 3 => 'error',
            (int) $priority === 4 => 'warn',
            (int) $priority <= 6 => 'info',
            default => 'debug',
        };
    }
}
