<?php

namespace App\Livewire\Project\Shared;

use App\Livewire\Concerns\BuildsTrafficChartPayload;
use App\Models\Application;
use App\Models\Service;
use App\Services\SentinelTrafficClient;
use App\Services\TrafficAnalyticsAggregator;
use App\Services\TrafficResource;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

/**
 * Traffic analytics tab of one resource (Application or Service). A resource can record
 * under several Sentinel keys (compose services, previews); every key is fetched and
 * merged into one view.
 */
abstract class ResourceTrafficAnalytics extends Component
{
    use AuthorizesRequests;
    use BuildsTrafficChartPayload;

    public string $chartId = 'resource-analytics';

    public string $range = '24h';

    public bool $enabled = false;

    public ?string $analyticsServerUuid = null;

    // Realtime refresh. Off by default (click "Live" to arm it). Only meaningful on the
    // 24h range; the 60s cadence matches the SentinelTrafficClient cache TTL and
    // Sentinel's per-minute rollups. The control is disabled for 7d/30d.
    public bool $live = false;

    public ?array $overview = null;

    public bool $latencyApproximate = false;

    public bool $uniquesApproximate = false;

    public array $topPaths = [];

    /** @var array<string, array<int, array<string, mixed>>> */
    public array $breakdowns = [];

    public ?string $attribution = null;

    /**
     * Per-bucket status-class time series for the stacked area chart. Empty when this
     * resource's Sentinel lacks the series endpoint, which flips the chart to the donut.
     *
     * @var array<int, array{bucket: int, s2xx: int, s3xx: int, s4xx: int, s5xx: int}>
     */
    public array $series = [];

    public bool $hasSeries = false;

    /** @var array<int, string> */
    protected array $breakdownDimensions = ['country', 'referer', 'browser', 'os', 'device', 'protocol', 'cache', 'status', 'agent', 'ip', 'useragent'];

    /**
     * Name of the public model property and lazy-load parameter ('application' / 'service').
     */
    abstract protected function resourceProperty(): string;

    protected function resourceModel(): Application|Service
    {
        return $this->{$this->resourceProperty()};
    }

    protected function trafficResource(): TrafficResource
    {
        return TrafficResource::for($this->resourceModel());
    }

    public function mount(): void
    {
        $this->authorize('view', $this->resourceModel());

        $server = $this->trafficResource()->server();
        $this->analyticsServerUuid = $server?->uuid;
        $this->enabled = (bool) $server?->isTrafficAnalyticsEnabled();

        if ($this->enabled) {
            $this->loadData();
        }
    }

    public function setRange(string $range): void
    {
        $this->range = in_array($range, ['24h', '7d', '30d'], true) ? $range : '24h';
        $this->loadData();
    }

    public function toggleLive(): void
    {
        if ($this->range !== '24h') {
            return;
        }
        $this->live = ! $this->live;
    }

    /**
     * Realtime polling is only armed when the user has it on and the range is 24h.
     */
    public function isLivePollable(): bool
    {
        return $this->live && $this->range === '24h';
    }

    public function loadData(): void
    {
        if (! $this->enabled) {
            return;
        }

        try {
            $this->authorize('view', $this->resourceModel());

            $resource = $this->trafficResource();
            [$from, $to] = SentinelTrafficClient::rangeWindow($this->range);
            $client = app(SentinelTrafficClient::class, ['server' => $resource->server()]);

            // Resolve every key of this resource and warm all of them in one or two
            // docker execs; the per-call methods below then read from cache.
            $keys = $client->prefetchResource($resource->uuid(), $from, $to, $this->breakdownDimensions, $this->range);

            $aggregator = new TrafficAnalyticsAggregator($this->breakdownDimensions);
            foreach ($keys as $key) {
                $aggregator->collect($client, $key, $from, $to, $this->range, fn (string $appKey) => $resource->domainForKey($appKey));
            }

            $result = $aggregator->overview();
            $this->overview = $result['overview']->toArray();
            $this->latencyApproximate = $result['latencyApproximate'];
            $this->uniquesApproximate = $result['uniquesApproximate'];
            $this->topPaths = $aggregator->topPaths();
            $this->breakdowns = $aggregator->breakdowns();
            $this->attribution = $aggregator->attribution();
            $this->series = $aggregator->series();
            $this->hasSeries = $this->series !== [];

            $this->dispatch("refreshChartData-{$this->chartId}-status", $this->chartPayload());
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    /**
     * Payload for the status chart: the stacked-area time series when available,
     * plus the donut totals as a fallback for older Sentinel builds.
     *
     * @return array<string, mixed>
     */
    protected function chartPayload(): array
    {
        $device = $this->deviceChartData();

        return [
            'hasSeries' => $this->hasSeries,
            'range' => $this->range,
            'seriesData' => [
                $this->overview['s2xx'] ?? 0,
                $this->overview['s3xx'] ?? 0,
                $this->overview['s4xx'] ?? 0,
                $this->overview['s5xx'] ?? 0,
            ],
            'timeSeries' => [
                'categories' => array_column($this->series, 'bucket'),
                'requests' => $this->requestsSpark(),
                's2xx' => array_column($this->series, 's2xx'),
                's3xx' => array_column($this->series, 's3xx'),
                's4xx' => array_column($this->series, 's4xx'),
                's5xx' => array_column($this->series, 's5xx'),
            ],
            'requestsSpark' => $this->requestsSpark(),
            'sparkCategories' => array_column($this->series, 'bucket'),
            'errorsSpark' => $this->errorsSpark(),
            'bandwidthSpark' => $this->bandwidthSpark(),
            'uniquesSpark' => $this->uniquesSpark(),
            'latencySpark' => $this->latencySpark(),
            'geo' => $this->geoMarkers(),
            'deviceLabels' => $device['labels'],
            'deviceSeries' => $device['series'],
        ];
    }

    public function errorRate(): float
    {
        if (! $this->overview || (int) ($this->overview['requests'] ?? 0) === 0) {
            return 0.0;
        }

        $errors = (int) ($this->overview['s4xx'] ?? 0) + (int) ($this->overview['s5xx'] ?? 0);

        return round(($errors / $this->overview['requests']) * 100, 2);
    }

    public function bandwidthBytes(): int
    {
        if (! $this->overview) {
            return 0;
        }

        return (int) ($this->overview['bytesIn'] ?? 0) + (int) ($this->overview['bytesOut'] ?? 0);
    }

    public function placeholder(array $params = []): View
    {
        $model = $params[$this->resourceProperty()] ?? null;

        if ($model instanceof Application || $model instanceof Service) {
            $this->authorize('view', $model);
            $server = TrafficResource::for($model)->server();

            if (! $server?->isTrafficAnalyticsEnabled()) {
                $this->{$this->resourceProperty()} = $model;
                $this->enabled = false;
                $this->analyticsServerUuid = $server?->uuid;

                return view('livewire.project.application.analytics');
            }
        }

        // Rendered instantly; the Sentinel round-trip runs in the deferred lazy-load request.
        return view('livewire.project.application.analytics-placeholder');
    }

    public function render()
    {
        return view('livewire.project.application.analytics');
    }
}
