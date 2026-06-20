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
            'type' => 'list_containers',
        ]);

        $data = $payload['data'] ?? [];

        return is_array($data) ? $data : [];
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
        $timeout = (float) config('flux.health_timeout_seconds', 1.0);
        $stream = @stream_socket_client("unix://{$socketPath}", $errorCode, $errorMessage, $timeout);

        if ($stream === false) {
            throw new RuntimeException($errorMessage ?: "Could not connect to Flux socket ({$errorCode}).");
        }

        stream_set_timeout($stream, (int) ceil($timeout));

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

        if (! str_starts_with($response, 'HTTP/1.1 200') && ! str_starts_with($response, 'HTTP/1.0 200')) {
            throw new RuntimeException('Flux dispatch did not return HTTP 200.');
        }

        $responseBody = str_contains($response, "\r\n\r\n") ? substr($response, strpos($response, "\r\n\r\n") + 4) : '';
        $payload = json_decode($responseBody, true);

        if (! is_array($payload)) {
            throw new RuntimeException('Flux dispatch returned an invalid response.');
        }

        if (($payload['status'] ?? null) === 'error') {
            $message = is_string($payload['message'] ?? null) ? $payload['message'] : 'Flux dispatch failed.';

            throw new RuntimeException($message);
        }

        return $payload;
    }
}
