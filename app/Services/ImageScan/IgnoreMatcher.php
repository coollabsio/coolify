<?php

namespace App\Services\ImageScan;

use App\Models\ImageScanIgnore;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Matches findings against the active ignores of one resource and its team.
 */
final class IgnoreMatcher
{
    /** @var array<string, list<ImageScanIgnore>> */
    private array $byVulnerability = [];

    /** @var array<string, list<ImageScanIgnore>> */
    private array $byPackage = [];

    /**
     * @param  Collection<int, ImageScanIgnore>  $ignores
     */
    private function __construct(Collection $ignores)
    {
        foreach ($ignores as $ignore) {
            if ($ignore->expires_at !== null && $ignore->expires_at->isPast()) {
                continue;
            }

            $vulnerabilityId = $this->normalize($ignore->vulnerability_id);
            $package = $this->normalizePackage($ignore->package);

            if ($vulnerabilityId !== null) {
                $this->byVulnerability[$vulnerabilityId][] = $ignore;
            } elseif ($package !== null) {
                $this->byPackage[$package][] = $ignore;
            }
        }
    }

    /**
     * @param  Collection<int, ImageScanIgnore>  $ignores
     */
    public static function fromIgnores(Collection $ignores): self
    {
        return new self($ignores);
    }

    public static function forResource(Model $resource): self
    {
        $teamId = method_exists($resource, 'team') ? $resource->team()?->id : null;

        if ($teamId === null) {
            return new self(collect());
        }

        $ignores = ImageScanIgnore::query()
            ->active()
            ->where('team_id', $teamId)
            ->where(function (Builder $query) use ($resource) {
                $query->whereNull('resource_type')
                    ->orWhere(function (Builder $query) use ($resource) {
                        $query->where('resource_type', $resource->getMorphClass())
                            ->where('resource_id', $resource->getKey());
                    });
            })
            ->get();

        return new self($ignores);
    }

    public function matches(Finding $finding): ?ImageScanIgnore
    {
        foreach ([$finding->id, ...$finding->aliases] as $candidate) {
            foreach ($this->byVulnerability[$this->normalize($candidate)] ?? [] as $ignore) {
                $package = $this->normalizePackage($ignore->package);

                if ($package === null || $package === $finding->package) {
                    return $ignore;
                }
            }
        }

        return $this->byPackage[$finding->package][0] ?? null;
    }

    private function normalize(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_strtolower($value);
    }

    private function normalizePackage(?string $value): ?string
    {
        return $value === null || $value === '' ? null : $value;
    }
}
