<?php

namespace App\Services;

use App\Models\Service;
use Illuminate\Support\Facades\Cache;

class TemplateUpdateChecker
{
    public static function currentHash(?string $serviceType): ?string
    {
        if ($serviceType === null || $serviceType === '') {
            return null;
        }

        return self::hashMap()[$serviceType] ?? null;
    }

    public static function updateAvailable(Service $service): bool
    {
        $current = self::currentHash($service->service_type);

        return $current !== null && $current !== $service->template_reference_hash;
    }

    public static function showBadge(Service $service): bool
    {
        if (! self::updateAvailable($service)) {
            return false;
        }

        return self::currentHash($service->service_type) !== $service->template_dismissed_hash;
    }

    /**
     * @return array<string, string|null>
     */
    private static function hashMap(): array
    {
        $fetchedAt = optional(get_service_templates_fetched_at())->toIso8601String() ?? '0';

        return Cache::remember("template-update-checker:{$fetchedAt}", now()->addDay(), function (): array {
            $map = [];
            foreach (get_service_templates() as $slug => $template) {
                $map[$slug] = TemplateFingerprint::forTemplate($template);
            }

            return $map;
        });
    }
}
