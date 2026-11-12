<?php

namespace App\Actions\Node\Build;

use App\Actions\Node\IssueNodeRegistryCredential;
use App\Enums\NodeBuildStatus;
use App\Models\Node;
use App\Models\NodeBuild;
use App\Models\NodeBuildLogLine;
use App\Models\NodeRegistry;
use App\Services\Security\SensitiveDataRedactor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;
use Throwable;

/**
 * Runs the build container on the builder Node and follows it until it ends.
 * Sentinel only starts the container (`image.build.v1`). The state comes from
 * `container.list` and the output from `container.logs`. The last line of a
 * successful build carries the image digest. In a cluster without a registry
 * the build stays on the builder Node: Sentinel loads it into its Podman store.
 */
class RunImageBuild
{
    use AsAction;

    private const POLL_SECONDS = 3;

    private const RESULT_PREFIX = 'COOLIFY_BUILD_RESULT ';

    /**
     * @param  array{source_type: string, dockerfile_path?: string, dockerfile_content?: string}  $source
     * @param  array{git_https_token?: string, git_ssh_private_key?: string}  $credential  Sent with this request only, never stored.
     * @return string The image digest, `sha256:...`.
     */
    public function handle(NodeBuild $build, Node $node, array $source, array $credential = []): string
    {
        foreach ($build->workload->buildCapabilities() as $capability) {
            $node->ensureReportedCapability($capability);
        }
        $push = self::pushTarget($build);
        if ($push !== []) {
            $node->ensureReportedCapability(IssueNodeRegistryCredential::PUSH_CAPABILITY);
        }
        // Build-time variables are sent with this request only, like the credential.
        $variables = $build->workload->buildVariables();
        $secrets = array_values(array_filter([...array_values($credential), $push['push_password'] ?? null, ...array_values($variables['secrets'])], fn (?string $value): bool => filled($value)));
        $local = $node->cluster?->needsRegistryServer() === false;
        $build->update([
            'node_id' => $node->id,
            'status' => NodeBuildStatus::RUNNING,
            'started_at' => now(),
            'image' => NodeRegistry::imageReference($build->workload, $build->uuid, $local),
        ]);
        self::flux('image.build', [
            'server_id' => $node->uuid,
            'command_id' => (string) Str::uuid(),
            'build_id' => $build->uuid,
            'builder_image' => (string) config('constants.node.builder_image'),
            ...($build->source_type->usesGit() ? [
                'repository_url' => $build->git_repository,
                'commit_sha' => $build->commit_sha,
                'base_directory' => trim((string) $build->base_directory, '/'),
            ] : []),
            'image' => $build->image,
            'dockerfile_path' => '',
            ...$source,
            ...$credential,
            ...$push,
            // Objects, because Flux reads maps and an empty PHP array encodes as a list.
            ...($variables['args'] === [] ? [] : ['build_args' => (object) $variables['args']]),
            ...($variables['secrets'] === [] ? [] : ['build_secrets' => (object) $variables['secrets']]),
        ], 660, $secrets);

        $deadline = now()->addMinutes((int) config('constants.node.build_timeout_minutes'));
        try {
            while (true) {
                $state = $this->containerState($node, $build);
                $this->appendLogs($node, $build, $secrets);
                if ($state !== null && ! in_array($state, ['created', 'running', 'configured', 'initialized', 'stopping'], true)) {
                    break;
                }
                if ($build->refresh()->status === NodeBuildStatus::CANCELLED) {
                    throw new RuntimeException('The build was cancelled.');
                }
                if (now()->greaterThan($deadline)) {
                    $this->lifecycle($node, $build, 'stop');
                    throw new RuntimeException('The build did not finish within '.config('constants.node.build_timeout_minutes').' minutes.');
                }
                Sleep::sleep(self::POLL_SECONDS);
            }
            // The container exited; read what it wrote after the last poll.
            $this->appendLogs($node, $build, $secrets);
        } finally {
            $this->removeContainer($node, $build);
        }

        $last = $build->logLines()->latest('id')->value('line');
        $result = is_string($last) && str_starts_with($last, self::RESULT_PREFIX)
            ? json_decode(substr($last, strlen(self::RESULT_PREFIX)), true)
            : null;
        $digest = is_array($result) ? ($result['digest'] ?? null) : null;
        if (! is_string($digest) || preg_match('/^sha256:[0-9a-f]{64}$/', $digest) !== 1) {
            $pushError = $push === [] ? null : $build->logLines()->where('line', 'like', 'error: the push to %')->latest('id')->value('line');
            throw new RuntimeException(is_string($pushError) ? ucfirst(substr($pushError, strlen('error: '))) : 'The build failed. See the build log for the reason.');
        }
        if ($local) {
            self::flux('image.load', [
                'server_id' => $node->uuid,
                'command_id' => (string) Str::uuid(),
                'build_id' => $build->uuid,
                'image' => $build->image,
                'keep_tags' => $node->cluster->registry?->keep_tags ?? 5,
            ], 660);
        }

        return $digest;
    }

    private function containerState(Node $node, NodeBuild $build): ?string
    {
        $result = self::flux('container.list', ['server_id' => $node->uuid]);
        $container = collect(data_get($result, 'containers', []))
            ->first(fn ($container): bool => data_get($container, 'name') === $build->containerName());

        return $container === null ? 'missing' : strtolower((string) data_get($container, 'state'));
    }

    /**
     * The external image that the build also pushes (v4 "Docker registry image name"), with the
     * registry login of the workload. Empty without an image name.
     *
     * @return array{push_image?: string, push_username?: string, push_password?: string}
     */
    public static function pushTarget(NodeBuild $build): array
    {
        $workload = $build->workload;
        if (blank($workload->docker_registry_image_name)) {
            return [];
        }
        $image = $workload->docker_registry_image_name.':'.(filled($workload->docker_registry_image_tag) ? $workload->docker_registry_image_tag : 'latest');
        $login = IssueNodeRegistryCredential::run($workload, $image);

        return [
            'push_image' => $image,
            ...($login === null ? [] : ['push_username' => $login['username'], 'push_password' => $login['password']]),
        ];
    }

    /**
     * Appends the lines that are newer than the stored cursor. A credential that the builder prints is hidden.
     *
     * @param  list<string>  $secrets
     */
    private function appendLogs(Node $node, NodeBuild $build, array $secrets): void
    {
        $payload = [
            'server_id' => $node->uuid,
            'command_id' => (string) Str::uuid(),
            'name' => $build->containerName(),
            'lines' => 10_000,
        ];
        $cursor = $build->log_cursor;
        if ($cursor !== null) {
            // Seconds only: lines of the cursor second come again and are skipped below.
            $payload['since_unix_seconds'] = (int) substr($cursor, 0, 10);
        }
        $result = self::flux('container.logs', $payload);
        $rows = [];
        foreach (preg_split('/\R/', (string) data_get($result, 'logs', '')) ?: [] as $raw) {
            [$timestamp, $line] = array_pad(explode(' ', $raw, 2), 2, '');
            $key = self::sortKey($timestamp);
            if ($key === null || ($cursor !== null && strcmp($key, $cursor) <= 0)) {
                continue;
            }
            $rows[] = [
                'node_build_id' => $build->id,
                'logged_at' => CarbonImmutable::createFromTimestampUTC(substr($key, 0, 10).'.'.substr($key, 11, 6)),
                'line' => mb_substr($secrets === [] ? $line : str_replace($secrets, REDACTED, $line), 0, 10_000),
            ];
            $cursor = $key;
        }
        if ($rows !== []) {
            foreach (array_chunk($rows, 500) as $chunk) {
                NodeBuildLogLine::query()->insert($chunk);
            }
            $build->update(['log_cursor' => $cursor]);
        }
    }

    /**
     * `<unix seconds, 10 digits>.<nanoseconds, 9 digits>` of an RFC 3339 time, so that string
     * order is time order. Null when the text is not a time.
     */
    public static function sortKey(string $timestamp): ?string
    {
        if (preg_match('/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})(?:\.(\d{1,9}))?(Z|[+-]\d{2}:\d{2})$/', $timestamp, $match) !== 1) {
            return null;
        }
        try {
            $seconds = (new \DateTimeImmutable($match[1].$match[3]))->getTimestamp();
        } catch (Throwable) {
            return null;
        }

        return sprintf('%010d.%s', $seconds, str_pad($match[2] ?? '', 9, '0'));
    }

    private function lifecycle(Node $node, NodeBuild $build, string $action): void
    {
        self::flux('workload.lifecycle', [
            'server_id' => $node->uuid,
            'command_id' => (string) Str::uuid(),
            'name' => $build->containerName(),
            'action' => $action,
        ], 150);
    }

    /** The log is stored in Coolify, so the container can go. */
    private function removeContainer(Node $node, NodeBuild $build): void
    {
        try {
            $this->lifecycle($node, $build, 'remove');
        } catch (Throwable) {
            // A leftover build container has no restart policy and only uses disk until removed.
        }
    }

    /**
     * Sends one direct command through the Flux internal API and returns its JSON result.
     *
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $secrets  Values of the payload that an error text must not show.
     * @return array<string, mixed>
     */
    public static function flux(string $verb, array $payload, int $timeout = 30, array $secrets = []): array
    {
        $url = config('constants.flux.internal_url');
        $token = config('constants.flux.internal_token');
        if (! is_string($url) || $url === '' || ! is_string($token) || $token === '') {
            throw new RuntimeException('Flux internal API configuration is incomplete.');
        }
        $response = Http::withToken($token)->acceptJson()->connectTimeout(10)->timeout($timeout)
            ->post(rtrim($url, '/').'/v1/commands/'.$verb, $payload);
        if ($response->failed()) {
            // An error can echo the request, which may carry a Git credential.
            $reason = trim(mb_substr(app(SensitiveDataRedactor::class)->redactText($response->body(), lockedSecrets: $secrets), 0, 1000));
            throw new RuntimeException($response->status() === 409
                ? "The server does not support {$verb}. Upgrade Sentinel and try again."
                : ($reason === '' ? "Flux rejected {$verb} with HTTP {$response->status()}." : $reason));
        }
        $data = $response->json();
        if (! is_array($data)) {
            throw new RuntimeException("Flux returned an invalid {$verb} result.");
        }

        return $data;
    }
}
