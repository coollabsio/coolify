<?php

namespace App\Services\Flux;

class FluxHealth
{
    /**
     * @return array{available: bool, label: string, message: string, socket: string|null}
     */
    public function check(): array
    {
        $socketPath = config('flux.unix_socket_path');

        if (! is_string($socketPath) || $socketPath === '') {
            return $this->unavailable(null, 'Flux socket is not configured.');
        }

        if (! file_exists($socketPath)) {
            return $this->unavailable($socketPath, 'Flux socket was not found.');
        }

        $timeout = (float) config('flux.health_timeout_seconds', 1.0);
        $stream = @stream_socket_client("unix://{$socketPath}", $errorCode, $errorMessage, $timeout);

        if ($stream === false) {
            return $this->unavailable($socketPath, $errorMessage ?: "Could not connect to Flux socket ({$errorCode}).");
        }

        stream_set_timeout($stream, (int) ceil($timeout));

        fwrite($stream, "GET /v1/health HTTP/1.1\r\nHost: flux\r\nAccept: application/json\r\nConnection: close\r\n\r\n");
        $response = stream_get_contents($stream) ?: '';
        fclose($stream);

        if (! str_starts_with($response, 'HTTP/1.1 200') && ! str_starts_with($response, 'HTTP/1.0 200')) {
            return $this->unavailable($socketPath, 'Flux health endpoint did not return HTTP 200.');
        }

        $body = str_contains($response, "\r\n\r\n") ? substr($response, strpos($response, "\r\n\r\n") + 4) : '';
        $payload = json_decode($body, true);

        if (! is_array($payload) || ($payload['ok'] ?? false) !== true) {
            return $this->unavailable($socketPath, 'Flux health endpoint returned an invalid response.');
        }

        return [
            'available' => true,
            'label' => 'Running',
            'message' => 'Flux is running.',
            'socket' => $socketPath,
        ];
    }

    /**
     * @return array{available: false, label: string, message: string, socket: string|null}
     */
    private function unavailable(?string $socketPath, string $message): array
    {
        return [
            'available' => false,
            'label' => 'Unavailable',
            'message' => $message,
            'socket' => $socketPath,
        ];
    }
}
