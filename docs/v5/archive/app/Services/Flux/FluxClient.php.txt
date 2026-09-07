<?php

namespace App\Services\Flux;

use App\Exceptions\V5\UnsupportedCooldVerb;
use Illuminate\Support\Str;
use RuntimeException;

class FluxClient
{
    /**
     * @return array<int, array{id?: string, name?: string, image?: string, state?: string, networks?: array<int, string>}>
     */
    public function listContainers(string $hostId): array
    {
        $payload = $this->dispatch($hostId, [
            'type' => 'containers.list',
        ]);

        $data = $payload['data'] ?? [];

        return is_array($data) ? $data : [];
    }

    public function pullImage(string $hostId, string $image): string
    {
        $payload = $this->dispatch($hostId, [
            'type' => 'images.pull',
            'reference' => $image,
        ]);

        return $this->output($payload, 'Image pulled.');
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    public function createContainer(string $hostId, array $spec): string
    {
        $payload = $this->dispatch($hostId, [
            'type' => 'containers.create',
            ...$spec,
        ]);
        $data = $payload['data'] ?? [];
        $id = is_array($data) && is_string($data['id'] ?? null) ? $data['id'] : '';

        if ($id === '') {
            throw new RuntimeException('Flux did not return a container id.');
        }

        return $id;
    }

    public function startContainer(string $hostId, string $id): string
    {
        $payload = $this->dispatch($hostId, [
            'type' => 'containers.start',
            'id' => $id,
        ]);

        return $this->output($payload, 'Container started.');
    }

    public function stopContainer(string $hostId, string $id, int $timeoutSeconds = 10): string
    {
        $payload = $this->dispatch($hostId, [
            'type' => 'containers.stop',
            'id' => $id,
            'timeout_seconds' => max(0, $timeoutSeconds),
        ]);

        return $this->output($payload, 'Container stopped.');
    }

    public function removeContainer(string $hostId, string $id, bool $force = false): string
    {
        $payload = $this->dispatch($hostId, [
            'type' => 'containers.delete',
            'id' => $id,
            'force' => $force,
        ]);

        return $this->output($payload, 'Container removed.');
    }

    /**
     * @return array<string, mixed>
     */
    public function inspectContainer(string $hostId, string $id): array
    {
        $payload = $this->dispatch($hostId, [
            'type' => 'containers.inspect',
            'id' => $id,
        ]);
        $data = $payload['data'] ?? [];

        return is_array($data) ? $data : [];
    }

    /**
     * @param  array<int, array{name: string, config: string}>  $apps
     */
    public function applyIngress(string $hostId, string $kind, string $config, array $apps = [], string $meshNetwork = 'coolify-default-mesh'): string
    {
        $payload = $this->dispatch($hostId, [
            'type' => 'ingress.apply',
            'kind' => $kind,
            'config' => $config,
            'apps' => $apps,
            'mesh_network' => $meshNetwork,
        ]);

        return $this->output($payload, 'Ingress applied.');
    }

    public function stopIngress(string $hostId, string $kind): string
    {
        $payload = $this->dispatch($hostId, [
            'type' => 'ingress.stop',
            'kind' => $kind,
        ]);

        return $this->output($payload, 'Ingress stopped.');
    }

    /**
     * @param  array{id: string, namespace: string, src: string, dst: string, proto: string, port: int}  $rule
     */
    public function applyFirewallRule(string $hostId, array $rule): string
    {
        $payload = $this->dispatch($hostId, [
            'type' => 'firewall.allow',
            ...$rule,
        ]);

        $data = $payload['data'] ?? [];
        $id = is_array($data) && is_string($data['id'] ?? null) ? $data['id'] : '';

        return $id !== '' ? $id : $this->output($payload, 'Firewall rule applied.');
    }

    public function revokeFirewallRule(string $hostId, string $id): string
    {
        $payload = $this->dispatch($hostId, [
            'type' => 'firewall.revoke',
            'id' => $id,
        ]);

        return $this->output($payload, 'Firewall rule removed.');
    }

    /**
     * @return array<int, array{id?: string, namespace?: string, src?: string, dst?: string, proto?: string, port?: int}>
     */
    public function listFirewallRules(string $hostId, string $namespace = ''): array
    {
        $payload = $this->dispatch($hostId, [
            'type' => 'firewall.list',
            'namespace' => $namespace,
        ]);

        $data = $payload['data'] ?? [];

        return is_array($data) ? $data : [];
    }

    public function cooldLogs(string $hostId, int $tail = 200): string
    {
        $payload = $this->dispatch($hostId, [
            'type' => 'coold.logs',
            'tail' => max(1, min($tail, 1000)),
        ]);

        return $this->output($payload, 'No coold logs returned.');
    }

    public function containerLogs(string $hostId, string $containerId, int $tail = 200): string
    {
        $payload = $this->dispatch($hostId, [
            'type' => 'containers.logs',
            'id' => $containerId,
            'tail' => max(1, min($tail, 1000)),
            'stdout' => true,
            'stderr' => true,
        ]);

        return $this->output($payload, 'No container logs returned.');
    }

    public function corrosionTables(string $hostId, int $limit = 200): string
    {
        $payload = $this->dispatch($hostId, [
            'type' => 'corrosion.tables',
            'limit' => max(1, min($limit, 1000)),
        ]);

        return $this->output($payload, '{"limit":200,"tables":[]}');
    }

    /**
     * Deliver a freshly minted host JWT to the node over the live coold RPC
     * stream (flux gates the `host.jwt.set` capability; the token must carry
     * it). Preferred over the SSH push because it reuses the already
     * authenticated flux<->coold channel and works while the current token is
     * still valid — exactly the rotation window. Throws like the sibling
     * dispatch methods (host not connected / UnsupportedCooldVerb / generic
     * failure) so the caller can catch and fall back to SSH.
     */
    public function pushHostToken(string $hostId, string $token): void
    {
        $this->dispatch($hostId, [
            'type' => 'host.jwt.set',
            'jwt' => $token,
        ]);
    }

    /**
     * Revoke a host token by its `jti` on the flux revocation store so flux
     * rejects it at verify immediately, instead of waiting for the token's TTL
     * to lapse (flux/src/unix_bridge.rs `POST /v1/tokens/revoke`,
     * flux/src/auth.rs `is_revoked`). The optional `expiresAt` (the token `exp`,
     * unix seconds) lets flux prune the denylist entry once it can no longer
     * matter.
     *
     * Best-effort like the sibling dispatch methods: throws a RuntimeException on
     * connection failure / timeout / non-2xx so the caller can catch and treat
     * an unreachable flux as non-fatal (the local revocation record still
     * stands and the short TTL + rotation bound the exposure).
     */
    public function revokeToken(string $jti, ?int $expiresAt = null): void
    {
        if (trim($jti) === '') {
            return;
        }

        $requestBody = ['jti' => $jti];

        if ($expiresAt !== null) {
            $requestBody['expires_at'] = $expiresAt;
        }

        $body = json_encode($requestBody, JSON_THROW_ON_ERROR);
        $response = $this->sendOverSocket('/v1/tokens/revoke', $body);
        $statusCode = $this->statusCode($response);

        if ($statusCode < 200 || $statusCode >= 300) {
            $responseBody = $this->responseBody($response);
            $payload = $responseBody === '' ? null : json_decode($responseBody, true);

            throw new RuntimeException(
                $this->errorMessage($payload, $responseBody) ?? "Flux token revocation returned HTTP {$statusCode}."
            );
        }
    }

    /**
     * @param  array<string, mixed>  $command
     * @return array<string, mixed>
     */
    private function dispatch(string $hostId, array $command): array
    {
        $body = json_encode([
            'host_id' => $hostId,
            'request_id' => (string) Str::uuid(),
            'command' => $command,
        ], JSON_THROW_ON_ERROR);

        $response = $this->sendOverSocket('/v1/coold/dispatch', $body);

        $statusCode = $this->statusCode($response);
        $responseBody = $this->responseBody($response);
        $payload = $responseBody === '' ? null : json_decode($responseBody, true);

        if ($statusCode < 200 || $statusCode >= 300) {
            throw $this->dispatchException(
                $command,
                $statusCode,
                $this->errorMessage($payload, $responseBody) ?? "Flux dispatch returned HTTP {$statusCode}."
            );
        }

        if (! is_array($payload)) {
            throw new RuntimeException('Flux dispatch returned an invalid response.');
        }

        if (($payload['status'] ?? null) === 'error') {
            $message = is_string($payload['message'] ?? null) ? $payload['message'] : 'Flux dispatch failed.';

            throw $this->dispatchException($command, $statusCode, $message);
        }

        return $payload;
    }

    /**
     * Send a single HTTP/1.1 request over the flux Unix-domain socket and return
     * the raw response. Shared by every flux verb (coold dispatch, host token
     * rotation, token revocation) — only the request path and JSON body differ.
     */
    private function sendOverSocket(string $path, string $body): string
    {
        $socketPath = config('flux.unix_socket_path');

        if (! is_string($socketPath) || $socketPath === '') {
            throw new RuntimeException('Flux socket is not configured.');
        }

        if (! file_exists($socketPath)) {
            throw new RuntimeException('Flux socket was not found.');
        }

        $connectionTimeout = (float) config('flux.connection_timeout_seconds', 1.0);
        $dispatchTimeout = (float) config('flux.dispatch_timeout_seconds', 35.0);
        $stream = @stream_socket_client("unix://{$socketPath}", $errorCode, $errorMessage, $connectionTimeout);

        if ($stream === false) {
            throw new RuntimeException($errorMessage ?: "Could not connect to Flux socket ({$errorCode}).");
        }

        stream_set_timeout($stream, (int) ceil($dispatchTimeout));

        fwrite($stream, implode("\r\n", [
            "POST {$path} HTTP/1.1",
            'Host: flux',
            'Accept: application/json',
            'Content-Type: application/json',
            'Content-Length: '.strlen($body),
            'Connection: close',
            '',
            $body,
        ]));

        $response = stream_get_contents($stream) ?: '';
        fclose($stream);

        return $response;
    }

    /**
     * Flux answers a verb the node's coold did not advertise with HTTP 501 and
     * the message "primitive <verb> is not supported by host" (coold repo:
     * flux/src/routing.rs:50-53, flux/src/unix_bridge.rs:227-245). Anything
     * else — including coold-side command failures relayed with their own
     * status code — is a generic dispatch failure.
     *
     * @param  array<string, mixed>  $command
     */
    private function dispatchException(array $command, int $statusCode, string $message): RuntimeException
    {
        $verb = is_string($command['type'] ?? null) ? $command['type'] : 'unknown';

        if ($statusCode === 501 || preg_match('/primitive .+ is not supported by host/i', $message) === 1) {
            return new UnsupportedCooldVerb($verb, $message);
        }

        return new RuntimeException($message);
    }

    private function statusCode(string $response): int
    {
        if ($response === '') {
            throw new RuntimeException('Flux did not return a response before the timeout. Check that coold is connected to Flux and try again.');
        }

        if (preg_match('/^HTTP\/\d(?:\.\d)?\s+(\d{3})/', $response, $matches) !== 1) {
            throw new RuntimeException('Could not talk to Flux. Check that Flux is running in the Coolify container.');
        }

        return (int) $matches[1];
    }

    private function responseBody(string $response): string
    {
        $position = strpos($response, "\r\n\r\n");

        return $position === false ? '' : substr($response, $position + 4);
    }

    private function errorMessage(mixed $payload, string $responseBody): ?string
    {
        if (is_array($payload) && is_string($payload['message'] ?? null) && $payload['message'] !== '') {
            return $payload['message'];
        }

        $message = trim($responseBody);

        return $message === '' ? null : Str::limit($message, 1000);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function output(array $payload, string $fallback): string
    {
        $data = $payload['data'] ?? [];
        $output = is_array($data) && is_string($data['output'] ?? null) ? $data['output'] : '';

        return $output !== '' ? $output : $fallback;
    }
}
