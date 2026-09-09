<?php

namespace App\Livewire\Project\Application;

use App\Models\Application;
use App\Services\SentinelTrafficClient;
use Livewire\Attributes\Lazy;
use Livewire\Component;

/**
 * Compact last-24h traffic KPI card for the application General page. Lazy-loaded so
 * the General page isn't blocked by Sentinel's docker-exec round-trip.
 */
#[Lazy]
class TrafficOverview extends Component
{
    public Application $application;

    public bool $enabled = false;

    public bool $eligible = false;

    public ?string $serverUuid = null;

    public ?array $overview = null;

    public function mount(): void
    {
        // Runs in the deferred lazy-load request, so the Sentinel fetch never blocks
        // the initial General-page render.
        $server = $this->application->destination?->server;
        $this->serverUuid = $server?->uuid;
        $this->enabled = (bool) $server?->isTrafficAnalyticsEnabled();
        $this->eligible = $server ? (! $server->isSwarm() && ! $server->isBuildServer()) : false;

        if ($this->enabled && $server) {
            try {
                $client = app(SentinelTrafficClient::class, ['server' => $server]);
                $this->overview = $client->appOverview($this->application->uuid, '24h')->toArray();
            } catch (\Throwable $e) {
                $this->overview = null;
            }
        }
    }

    public function hasData(): bool
    {
        return $this->overview !== null && (int) ($this->overview['requests'] ?? 0) > 0;
    }

    public function errorRate(): float
    {
        if (! $this->overview || (int) ($this->overview['requests'] ?? 0) === 0) {
            return 0.0;
        }

        $errors = (int) ($this->overview['s4xx'] ?? 0) + (int) ($this->overview['s5xx'] ?? 0);

        return round(($errors / $this->overview['requests']) * 100, 2);
    }

    public function placeholder(): string
    {
        return <<<'HTML'
        <div class="h-24 w-full animate-pulse rounded-xl border border-neutral-200 bg-neutral-50 dark:border-white/[0.08] dark:bg-white/[0.02]"></div>
        HTML;
    }

    public function render()
    {
        return view('livewire.project.application.traffic-overview');
    }
}
