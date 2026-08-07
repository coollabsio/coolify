<?php

namespace App\Services;

use App\Exceptions\RateLimitException;
use Illuminate\Support\Facades\Http;

class HostingerService
{
    private string $baseUrl = 'https://developers.hostinger.com';

    public function __construct(private string $token) {}

    private function request(string $method, string $endpoint, array $data = []): array
    {
        $response = Http::withToken($this->token)
            ->acceptJson()
            ->asJson()
            ->timeout(30)
            ->connectTimeout(10)
            ->retry(3, fn (int $attempt) => $attempt * 100, throw: false)
            ->{$method}($this->baseUrl.$endpoint, $data);

        if (! $response->successful()) {
            if ($response->status() === 429) {
                throw new RateLimitException(
                    'Rate limit exceeded. Please try again later.',
                    $response->header('Retry-After') !== null ? (int) $response->header('Retry-After') : null
                );
            }

            $message = $response->json('message')
                ?? $response->json('error')
                ?? 'Unknown error';

            throw new \Exception('Hostinger API error: '.$message, $response->status());
        }

        return $response->json() ?? [];
    }

    public function getDataCenters(): array
    {
        return $this->request('get', '/api/vps/v1/data-centers');
    }

    public function getTemplates(): array
    {
        return $this->request('get', '/api/vps/v1/templates');
    }

    public function getCatalogItems(): array
    {
        return $this->request('get', '/api/billing/v1/catalog', ['category' => 'VPS']);
    }

    public function purchaseVirtualMachine(array $params): array
    {
        $response = $this->request('post', '/api/vps/v1/virtual-machines', $params);

        return $response['virtual_machine'] ?? [];
    }

    public function getVirtualMachine(int $virtualMachineId): array
    {
        return $this->request('get', $this->virtualMachineEndpoint($virtualMachineId));
    }

    public function getVirtualMachines(): array
    {
        return $this->request('get', '/api/vps/v1/virtual-machines');
    }

    public function waitForPublicIp(array $virtualMachine, int $attempts = 30, int $sleepMilliseconds = 1000): array
    {
        if ($this->getPublicIpAddress($virtualMachine) || empty($virtualMachine['id'])) {
            return $virtualMachine;
        }

        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            usleep($sleepMilliseconds * 1000);

            $virtualMachine = $this->getVirtualMachine((int) $virtualMachine['id']);

            if ($this->getPublicIpAddress($virtualMachine)) {
                return $virtualMachine;
            }
        }

        return $virtualMachine;
    }

    public function getPublicIpAddress(array $virtualMachine): ?string
    {
        foreach (['ipv4', 'ipv6'] as $version) {
            foreach ($virtualMachine[$version] ?? [] as $ipAddress) {
                if (filled($ipAddress['address'] ?? null)) {
                    return $ipAddress['address'];
                }
            }
        }

        return null;
    }

    public function startVirtualMachine(int $virtualMachineId): array
    {
        return $this->request('post', $this->virtualMachineEndpoint($virtualMachineId).'/start');
    }

    public function findVirtualMachineByIp(string $ip): ?array
    {
        foreach ($this->getVirtualMachines() as $virtualMachine) {
            foreach (['ipv4', 'ipv6'] as $version) {
                foreach ($virtualMachine[$version] ?? [] as $ipAddress) {
                    if (($ipAddress['address'] ?? null) === $ip) {
                        return $virtualMachine;
                    }
                }
            }
        }

        return null;
    }

    private function virtualMachineEndpoint(int $virtualMachineId): string
    {
        return '/api/vps/v1/virtual-machines/'.$virtualMachineId;
    }
}
