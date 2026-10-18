<?php

namespace App\Services;

use App\Exceptions\RateLimitException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Arr;
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
            ->retry(
                $method === 'get' ? 3 : 1,
                fn (int $attempt) => $attempt * 100,
                fn (\Exception $exception) => ! $exception instanceof RequestException || $exception->response->serverError(),
                throw: false
            )
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

    /**
     * Get the KVM VPS plans. Game Panel plans share the VPS category but are not offered for Coolify servers.
     */
    public function getCatalogItems(): array
    {
        return collect($this->request('get', '/api/billing/v1/catalog', ['category' => 'VPS']))
            ->filter(fn ($item) => is_array($item) && preg_match('/-vps-kvm\d+$/', (string) ($item['id'] ?? '')) === 1)
            ->values()
            ->all();
    }

    public function getPublicKeys(): array
    {
        return $this->request('get', '/api/vps/v1/public-keys')['data'] ?? [];
    }

    public function getPostInstallScripts(): array
    {
        return $this->request('get', '/api/vps/v1/post-install-scripts')['data'] ?? [];
    }

    public function attachPublicKeys(int $virtualMachineId, array $publicKeyIds): array
    {
        return $this->request('post', "/api/vps/v1/public-keys/attach/{$virtualMachineId}", [
            'ids' => array_map('intval', array_values($publicKeyIds)),
        ]);
    }

    public function purchaseVirtualMachine(array $params): array
    {
        // Hostinger accepts only a fully qualified hostname and uses its own default otherwise.
        if (! str_contains($params['setup']['hostname'] ?? '', '.')) {
            unset($params['setup']['hostname']);
        }

        $existingVirtualMachineIds = collect($this->getVirtualMachines())->pluck('id')->all();

        try {
            $response = $this->request('post', '/api/vps/v1/virtual-machines', $params);
        } catch (\Throwable $e) {
            // Hostinger can charge for the VPS before its setup fails, so keep the VPS if one was created.
            $virtualMachine = $this->findPurchasedVirtualMachine($existingVirtualMachineIds);
            if (! $virtualMachine) {
                throw $e;
            }

            logger()->warning('Hostinger charged for a VPS but its setup failed', [
                'hostinger_virtual_machine_id' => $virtualMachine['id'],
                'error' => $e->getMessage(),
            ]);

            return $this->setupPurchasedVirtualMachine($virtualMachine, $params['setup']);
        }

        if (empty($response['virtual_machine']['id'])) {
            logger()->warning('Hostinger VPS order did not return a virtual machine', Arr::only($response, ['id', 'subscription_id', 'status', 'message']));

            throw new \Exception('Hostinger order '.($response['id'] ?? 'unknown').' is waiting for payment. Finish the VPS setup in hPanel.', 202);
        }

        return $response['virtual_machine'];
    }

    private function findPurchasedVirtualMachine(array $existingVirtualMachineIds): ?array
    {
        try {
            return collect($this->getVirtualMachines())
                ->first(fn (array $virtualMachine) => ! in_array($virtualMachine['id'] ?? null, $existingVirtualMachineIds, true));
        } catch (\Throwable) {
            return null;
        }
    }

    private function setupPurchasedVirtualMachine(array $virtualMachine, array $setup): array
    {
        if (($virtualMachine['state'] ?? null) !== 'initial') {
            return $virtualMachine;
        }

        try {
            return $this->request('post', $this->virtualMachineEndpoint((int) $virtualMachine['id']).'/setup', $setup);
        } catch (\Throwable $e) {
            logger()->warning('Hostinger VPS setup retry failed', [
                'hostinger_virtual_machine_id' => $virtualMachine['id'],
                'error' => $e->getMessage(),
            ]);

            return $virtualMachine;
        }
    }

    public function getVirtualMachine(int $virtualMachineId): array
    {
        return $this->request('get', $this->virtualMachineEndpoint($virtualMachineId));
    }

    public function getVirtualMachines(): array
    {
        return $this->request('get', '/api/vps/v1/virtual-machines');
    }

    /**
     * Briefly wait for a public IP. VPS setup usually takes longer, so the server status check fills in the IP later.
     */
    public function waitForPublicIp(array $virtualMachine, int $attempts = 10, int $sleepMilliseconds = 1000): array
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

    public function disableAutoRenewal(string $subscriptionId): array
    {
        return $this->request('delete', "/api/billing/v1/subscriptions/{$subscriptionId}/auto-renewal/disable");
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
