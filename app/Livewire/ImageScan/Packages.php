<?php

namespace App\Livewire\ImageScan;

use App\Models\ImageSbom;
use App\Models\ImageScan;
use App\Services\ImageScan\ResourceScans;
use App\Traits\ListensToTeamChannel;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The packages of the images a resource runs, from the stored package lists, with the SBOM downloads.
 */
class Packages extends Component
{
    use AuthorizesRequests;
    use ListensToTeamChannel;
    use WithPagination;

    private const PAGE_NAME = 'packagesPage';

    #[Locked]
    public string $resourceType;

    #[Locked]
    public string $resourceUuid;

    public string $search = '';

    /** @var list<string> */
    public array $types = [];

    /** @var list<string> Image refs, for pages with more than one image. */
    public array $images = [];

    public int $perPage = 10;

    private ?Model $loadedResource = null;

    public function getListeners(): array
    {
        return $this->teamChannelListeners([
            'ImageScanUpdated' => 'scanUpdated',
        ]);
    }

    public function mount(string $resourceType, string $resourceUuid): void
    {
        abort_unless(in_array($resourceType, ResourceScans::RESOURCE_TYPES, true), 404);
        $this->resourceType = $resourceType;
        $this->resourceUuid = $resourceUuid;
        $this->resource();
    }

    /**
     * @param  array<string, string>  $event
     */
    public function scanUpdated(array $event = []): void
    {
        $uuids = ResourceScans::owners($this->resource())
            ->map(fn (Model $owner): string => (string) $owner->getAttribute('uuid'))
            ->push($this->resourceUuid);

        if (! $uuids->contains((string) data_get($event, 'resourceUuid'))) {
            $this->skipRender();
        }
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'perPage'], true)) {
            $this->resetPage(self::PAGE_NAME);
        }
    }

    public function toggleType(string $type): void
    {
        $this->types = in_array($type, $this->types, true)
            ? array_values(array_diff($this->types, [$type]))
            : [...$this->types, $type];
        $this->resetPage(self::PAGE_NAME);
    }

    public function toggleImage(string $image): void
    {
        $this->images = in_array($image, $this->images, true)
            ? array_values(array_diff($this->images, [$image]))
            : [...$this->images, $image];
        $this->resetPage(self::PAGE_NAME);
    }

    /**
     * The "Packages and SBOM" link of an image row on the Security card: the packages of that image only.
     */
    #[On('security-show-image-packages')]
    public function showImage(string $image = ''): void
    {
        $this->images = $image === '' ? [] : [$image];
        $this->resetPage(self::PAGE_NAME);
    }

    public function resetFilters(): void
    {
        $this->types = [];
        $this->resetPage(self::PAGE_NAME);
    }

    private function resource(): Model
    {
        if ($this->loadedResource !== null) {
            return $this->loadedResource;
        }

        $user = auth()->user();
        abort_unless($user !== null, 403);

        $resource = ResourceScans::find($this->resourceType, $this->resourceUuid, $user->teams->pluck('id')->all());
        abort_unless($resource instanceof Model, 404);
        $this->authorize('view', $resource);

        return $this->loadedResource = $resource;
    }

    /**
     * @return array{routeName: string, parameters: array<string, string>}
     */
    private function downloadRoute(Model $resource): array
    {
        $environment = $resource->getAttribute('environment');

        return [
            'routeName' => "project.{$this->resourceType}.vulnerabilities.sbom",
            'parameters' => [
                'project_uuid' => (string) data_get($environment, 'project.uuid'),
                'environment_uuid' => (string) data_get($environment, 'uuid'),
                "{$this->resourceType}_uuid" => $this->resourceUuid,
            ],
        ];
    }

    public function render(): View
    {
        $resource = $this->resource();
        $scans = instanceSettings()->is_image_scanning_allowed
            ? ResourceScans::current($resource, null, ['id', 'uuid', 'resource_type', 'resource_id', 'deployment_queue_id', 'image_sbom_id', 'image_ref', 'scanned_at'])
                ->filter(fn (ImageScan $scan): bool => $scan->image_sbom_id !== null)
                ->values()
            : collect();

        $sboms = ImageSbom::query()->whereIn('id', $scans->pluck('image_sbom_id')->unique())->get()->keyBy('id');

        /** @var Collection<int, array{key: string, name: string, version: string, type: string, licenses: list<string>, purl: string, image: string}> $packages */
        $packages = $scans->flatMap(function (ImageScan $scan) use ($sboms): array {
            $sbom = $sboms->get($scan->image_sbom_id);

            return collect((array) $sbom?->packages)
                ->filter(fn ($row): bool => is_array($row))
                ->map(fn (array $row, int $index): array => [
                    'key' => $scan->uuid.'-'.$index,
                    'name' => (string) ($row['name'] ?? ''),
                    'version' => (string) ($row['version'] ?? ''),
                    'type' => (string) ($row['type'] ?? ''),
                    'licenses' => array_values(array_map('strval', (array) ($row['licenses'] ?? []))),
                    'purl' => (string) ($row['purl'] ?? ''),
                    'image' => (string) $scan->image_ref,
                ])
                ->all();
        })->values();

        $typeOptions = $packages->pluck('type')->filter()->unique()->sort()->values()->all();
        $imageOptions = $scans->pluck('image_ref')->map(fn ($ref): string => (string) $ref)->unique()->values()->all();
        $this->images = array_values(array_intersect($this->images, $imageOptions));
        $search = mb_strtolower(trim($this->search));
        $filtered = $packages
            ->when($this->types !== [], fn (Collection $rows) => $rows->filter(fn (array $row): bool => in_array($row['type'], $this->types, true)))
            ->when($this->images !== [], fn (Collection $rows) => $rows->filter(fn (array $row): bool => in_array($row['image'], $this->images, true)))
            ->when($search !== '', fn (Collection $rows) => $rows->filter(fn (array $row): bool => str_contains(mb_strtolower($row['name'].' '.$row['version'].' '.$row['purl']), $search)))
            ->sortBy([['name', 'asc'], ['version', 'asc']])
            ->values();

        $this->perPage = max(1, min(100, $this->perPage));
        $lastPage = max(1, (int) ceil($filtered->count() / $this->perPage));
        $page = max(1, min((int) $this->getPage(self::PAGE_NAME), $lastPage));
        if ($page !== (int) $this->getPage(self::PAGE_NAME)) {
            $this->setPage($page, self::PAGE_NAME);
        }

        $route = $this->downloadRoute($resource);

        return view('livewire.image-scan.packages', [
            'scans' => $scans,
            'total' => $packages->count(),
            'items' => new LengthAwarePaginator($filtered->forPage($page, $this->perPage)->values(), $filtered->count(), $this->perPage, $page, ['pageName' => self::PAGE_NAME]),
            'typeOptions' => $typeOptions,
            'imageOptions' => count($imageOptions) > 1 ? $imageOptions : [],
            'showsImage' => $scans->pluck('image_ref')->unique()->count() > 1,
            'truncated' => $sboms->contains(fn (ImageSbom $sbom): bool => (bool) $sbom->packages_truncated),
            'downloads' => $scans->map(fn (ImageScan $scan): array => [
                'image' => (string) $scan->image_ref,
                'cyclonedx' => route($route['routeName'], [...$route['parameters'], 'scan_uuid' => $scan->uuid, 'format' => 'cyclonedx']),
                'spdx' => route($route['routeName'], [...$route['parameters'], 'scan_uuid' => $scan->uuid, 'format' => 'spdx']),
            ])->all(),
        ]);
    }
}
