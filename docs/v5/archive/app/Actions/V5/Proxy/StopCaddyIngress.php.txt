<?php

namespace App\Actions\V5\Proxy;

use App\Enums\V5\IngressStatus;
use App\Exceptions\V5\UnsupportedCooldVerb;
use App\Models\V5\Server;
use App\Services\Flux\FluxClient;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;

class StopCaddyIngress
{
    use AsAction;

    private const FIREWALL_PORTS = [80];

    public function __construct(private readonly FluxClient $fluxClient) {}

    public function handle(Server $server): string
    {
        if (! $server->isIngress() && $server->ingress_type === null) {
            return 'Server is not an ingress server.';
        }

        $hostId = $server->fluxHostId();

        if (! is_string($hostId) || $hostId === '') {
            throw new \RuntimeException('Server is missing its Flux host id.');
        }

        // Revoke first: if stopping the container fails the allow rules must not
        // stay orphaned on the host.
        foreach (self::FIREWALL_PORTS as $port) {
            $this->revokeFirewallRuleIfPresent($hostId, "v5-caddy-ingress:{$port}");
        }

        $output = $this->fluxClient->stopIngress($hostId, 'caddy');

        if ($server->exists) {
            $server->update(['ingress_status' => IngressStatus::Exited->value]);
        }

        return $output;
    }

    private function revokeFirewallRuleIfPresent(string $hostId, string $ruleId): void
    {
        try {
            $this->fluxClient->revokeFirewallRule($hostId, $ruleId);
        } catch (UnsupportedCooldVerb $exception) {
            Log::warning('V5 caddy ingress firewall revoke skipped: coold verb unsupported', [
                'host_id' => $hostId,
                'rule_id' => $ruleId,
                'verb' => $exception->verb,
                'message' => $exception->getMessage(),
            ]);
        } catch (\RuntimeException $exception) {
            if (! str_contains(Str::lower($exception->getMessage()), 'not found')) {
                throw $exception;
            }
        }
    }
}
