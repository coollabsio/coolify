<?php

namespace App\Services\Flux;

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
     * @param  array<string, mixed>  $command
     * @return array<string, mixed>
     */
    private function dispatch(string $hostId, array $command): array
    {
        $socketPath = config('flux.unix_socket_path');

        if (! is_string($socketPath) || $socketPath === '') {
            throw new RuntimeException('Flux socket is not configured.');
        }

        if (! file_exists($socketPath)) {
            throw new RuntimeException('Flux socket was not found.');
        }

        $body = json_encode([
            'host_id' => $hostId,
            'request_id' => (string) Str::uuid(),
            'command' => $command,
        ], JSON_THROW_ON_ERROR);
        $connectionTimeout = (float) config('flux.connection_timeout_seconds', 1.0);
        $dispatchTimeout = (float) config('flux.dispatch_timeout_seconds', 35.0);
        $stream = @stream_socket_client("unix://{$socketPath}", $errorCode, $errorMessage, $connectionTimeout);

        if ($stream === false) {
            throw new RuntimeException($errorMessage ?: "Could not connect to Flux socket ({$errorCode}).");
        }

        stream_set_timeout($stream, (int) ceil($dispatchTimeout));

        fwrite($stream, implode("\r\n", [
            'POST /v1/coold/dispatch HTTP/1.1',
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

        $statusCode = $this->statusCode($response);
        $responseBody = $this->responseBody($response);
        $payload = $responseBody === '' ? null : json_decode($responseBody, true);

        if ($statusCode < 200 || $statusCode >= 300) {
            throw new RuntimeException($this->errorMessage($payload, $responseBody) ?? "Flux dispatch returned HTTP {$statusCode}.");
        }

        if (! is_array($payload)) {
            throw new RuntimeException('Flux dispatch returned an invalid response.');
        }

        if (($payload['status'] ?? null) === 'error') {
            $message = is_string($payload['message'] ?? null) ? $payload['message'] : 'Flux dispatch failed.';

            throw new RuntimeException($message);
        }

        return $payload;
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
