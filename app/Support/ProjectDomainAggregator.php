<?php

namespace App\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class ProjectDomainAggregator
{
    public static function forProjects(Collection $projects): array
    {
        if ($projects->isEmpty()) {
            return [];
        }

        $projectIds = $projects->pluck('id')->map(fn ($id) => (int) $id)->sort()->values();
        $cacheKey = 'project-dashboard-domains:v1:'.md5($projectIds->implode(','));

        return Cache::remember($cacheKey, 10, function () use ($projects, $projectIds): array {
            $byProjectId = [];
            foreach ($projectIds as $projectId) {
                $byProjectId[$projectId] = [];
            }

            $applications = DB::table('applications')
                ->join('environments', 'environments.id', '=', 'applications.environment_id')
                ->whereIn('environments.project_id', $projectIds)
                ->whereNull('applications.deleted_at')
                ->get([
                    'environments.project_id as project_id',
                    'applications.fqdn as fqdn',
                    'applications.docker_compose_domains as docker_compose_domains',
                ]);

            foreach ($applications as $application) {
                $projectId = (int) $application->project_id;
                self::addRawDomains($byProjectId[$projectId], $application->fqdn);
                self::addComposeDomains($byProjectId[$projectId], $application->docker_compose_domains);
            }

            $serviceApplications = DB::table('service_applications')
                ->join('services', 'services.id', '=', 'service_applications.service_id')
                ->join('environments', 'environments.id', '=', 'services.environment_id')
                ->whereIn('environments.project_id', $projectIds)
                ->whereNull('services.deleted_at')
                ->whereNull('service_applications.deleted_at')
                ->get([
                    'environments.project_id as project_id',
                    'service_applications.fqdn as fqdn',
                ]);

            foreach ($serviceApplications as $serviceApplication) {
                $projectId = (int) $serviceApplication->project_id;
                self::addRawDomains($byProjectId[$projectId], $serviceApplication->fqdn);
            }

            $result = [];
            foreach ($projects as $project) {
                $domains = array_values($byProjectId[(int) $project->id] ?? []);
                usort($domains, fn (array $first, array $second) => strcasecmp($first['label'], $second['label']));
                $result[$project->uuid] = $domains;
            }

            return $result;
        });
    }

    private static function addRawDomains(array &$bucket, mixed $raw): void
    {
        if (! is_string($raw) || trim($raw) === '') {
            return;
        }

        foreach (explode(',', $raw) as $value) {
            self::addOne($bucket, $value);
        }
    }

    private static function addComposeDomains(array &$bucket, mixed $raw): void
    {
        if (! is_string($raw) || trim($raw) === '') {
            return;
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return;
        }

        array_walk_recursive($decoded, function ($value) use (&$bucket): void {
            if (is_string($value)) {
                self::addRawDomains($bucket, $value);
            }
        });
    }

    private static function addOne(array &$bucket, mixed $raw): void
    {
        if (! is_string($raw)) {
            return;
        }

        $value = trim(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $value = trim($value, " \t\n\r\0\x0B\"'");
        if ($value === '') {
            return;
        }

        if (str_starts_with($value, '//')) {
            $value = 'https:'.$value;
        } elseif (! preg_match('#^https?://#i', $value)) {
            $value = 'https://'.$value;
        }

        $parts = parse_url($value);
        if (! is_array($parts) || empty($parts['host'])) {
            return;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        if (! in_array($scheme, ['http', 'https'], true)) {
            return;
        }

        $host = strtolower((string) $parts['host']);
        $port = isset($parts['port']) ? ':'.(int) $parts['port'] : '';
        $path = (string) ($parts['path'] ?? '');
        $path = $path === '/' ? '' : rtrim($path, '/');
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';
        $href = $scheme.'://'.$host.$port.$path.$query;
        $key = strtolower(rtrim($href, '/'));

        $bucket[$key] = [
            'href' => $href,
            'label' => $host.$port,
        ];
    }
}
