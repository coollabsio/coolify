<?php

namespace App\Services\ImageScan;

use App\Enums\ImageScanGateMode;
use App\Enums\ImageScanOnError;
use App\Models\Application;
use App\Models\ImageScanPolicy;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use App\Models\Team;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Resolves scan rules through Instance, Team, Server and App. A null value inherits.
 * Team and server come from the loaded resource, never from the session.
 */
class ImageScanPolicyResolver
{
    private const FIELDS = ['gate_mode', 'on_scan_error', 'count_medium'];

    /**
     * Rules always resolve through the main destination server (spec 3.1). The given server only decides where the
     * image is scanned and whether scanning is on there.
     *
     * @param  Model  $resource  Application, ServiceApplication, ServiceDatabase, Service or a standalone database.
     * @param  Server|null  $server  Overrides the scan server (the build server for a gated build).
     */
    public static function for(Model $resource, ?Server $server = null): ResolvedImageScanPolicy
    {
        $isApplication = $resource instanceof Application;
        $mainServer = self::mainServer($resource);
        $scanServer = $server ?? $mainServer;

        $resolved = self::resolveLevels(self::levelsFor($resource, $mainServer));
        $values = array_map(fn (array $field) => $field['value'], $resolved);
        $sources = array_map(fn (array $field) => $field['source'], $resolved);

        // Services and databases have no deploy to block.
        $gateMode = $isApplication ? $values['gate_mode'] : ImageScanGateMode::Off;

        return new ResolvedImageScanPolicy(
            gateMode: $gateMode->value,
            onScanError: $values['on_scan_error'],
            scanEnabled: self::isScanEnabled($resource, $scanServer),
            sources: $sources,
            scanServer: $scanServer,
            countMedium: $values['count_medium'],
        );
    }

    /**
     * The "Count medium problems" rule of a resource. Reads only the rule rows, not the scan settings.
     */
    public static function countMediumFor(Model $resource): bool
    {
        return self::resolveLevels(self::levelsFor($resource, self::mainServer($resource)))['count_medium']['value'];
    }

    /**
     * `countMediumFor()` for many resources of one team with a fixed number of queries. Reads the loaded main server
     * of each resource (`ResourceScans::loadScanRelations()`).
     *
     * @param  Collection<int, Model>  $resources
     * @return array<string, bool> Keyed by `ResourceScans::key()`.
     */
    public static function countMediumForMany(int $teamId, Collection $resources): array
    {
        if ($resources->isEmpty()) {
            return [];
        }

        $servers = $resources->mapWithKeys(fn (Model $resource): array => [ResourceScans::key($resource) => self::mainServer($resource)]);
        $applications = $resources->filter(fn (Model $resource): bool => $resource instanceof Application);
        $scopes = [
            (new Team)->getMorphClass() => [$teamId],
            (new Server)->getMorphClass() => $servers->filter()->map(fn (Server $server): int => (int) $server->id)->unique()->values()->all(),
            (new Application)->getMorphClass() => $applications->map(fn (Application $application): int => (int) $application->id)->values()->all(),
        ];
        $rows = ImageScanPolicy::query()
            ->where(function ($query) use ($scopes): void {
                foreach (array_filter($scopes) as $type => $ids) {
                    $query->orWhere(fn ($query) => $query->where('scope_type', $type)->whereIn('scope_id', $ids));
                }
            })
            ->get()
            ->keyBy(fn (ImageScanPolicy $row): string => $row->scope_type.':'.$row->scope_id);
        $row = fn (Model $scope): ?ImageScanPolicy => $rows->get($scope->getMorphClass().':'.$scope->getKey());
        $instance = self::instanceRow();
        $team = $rows->get((new Team)->getMorphClass().':'.$teamId);

        return $resources->mapWithKeys(function (Model $resource) use ($servers, $row, $instance, $team): array {
            $key = ResourceScans::key($resource);
            $levels = [['instance', $instance], ['team', $team]];
            if ($servers[$key] instanceof Server) {
                $levels[] = ['server', $row($servers[$key])];
            }
            if ($resource instanceof Application) {
                $levels[] = ['app', $row($resource)];
            }

            return [$key => self::resolveLevels($levels)['count_medium']['value']];
        })->all();
    }

    /**
     * @return list<array{0: string, 1: ?ImageScanPolicy}>
     */
    private static function levelsFor(Model $resource, ?Server $mainServer): array
    {
        $levels = [['instance', self::instanceRow()]];
        $team = method_exists($resource, 'team') ? $resource->team() : null;
        if ($team instanceof Model) {
            $levels[] = ['team', self::scopeRow($team)];
        }
        if ($mainServer instanceof Server) {
            $levels[] = ['server', self::scopeRow($mainServer)];
        }
        if ($resource instanceof Application) {
            $levels[] = ['app', self::scopeRow($resource)];
        }

        return $levels;
    }

    /**
     * The row to edit for a level: the instance row for null, else the existing or a new unsaved row.
     */
    public static function forScope(?Model $scope): ImageScanPolicy
    {
        if ($scope === null) {
            return self::instanceRow() ?? new ImageScanPolicy;
        }

        return ImageScanPolicy::query()->firstOrNew([
            'scope_type' => $scope->getMorphClass(),
            'scope_id' => $scope->getKey(),
        ]);
    }

    /**
     * The values a level inherits from the levels above it, each with the level it comes from.
     *
     * @param  Model  $scope  Team, Server or Application.
     * @return array{gate_mode: array{value: ImageScanGateMode, source: string}, on_scan_error: array{value: ImageScanOnError, source: string}, count_medium: array{value: bool, source: string}}
     */
    public static function inheritedFor(Model $scope): array
    {
        $levels = [['instance', self::instanceRow()]];
        $team = match (true) {
            $scope instanceof Server => $scope->team,
            method_exists($scope, 'team') => $scope->team(),
            default => null,
        };
        if ($team instanceof Model) {
            $levels[] = ['team', self::scopeRow($team)];
        }
        if ($scope instanceof Application) {
            $server = self::mainServer($scope);
            if ($server instanceof Server) {
                $levels[] = ['server', self::scopeRow($server)];
            }
        }

        return self::resolveLevels($levels);
    }

    /**
     * @param  list<array{0: string, 1: ?ImageScanPolicy}>  $levels  From the top level down.
     * @return array{gate_mode: array{value: ImageScanGateMode, source: string}, on_scan_error: array{value: ImageScanOnError, source: string}, count_medium: array{value: bool, source: string}}
     */
    private static function resolveLevels(array $levels): array
    {
        $resolved = [
            'gate_mode' => ['value' => ImageScanGateMode::Off, 'source' => 'instance'],
            'on_scan_error' => ['value' => ImageScanOnError::Deploy, 'source' => 'instance'],
            'count_medium' => ['value' => false, 'source' => 'instance'],
        ];

        foreach ($levels as [$source, $row]) {
            if (! $row instanceof ImageScanPolicy) {
                continue;
            }
            foreach (self::FIELDS as $field) {
                if ($row->{$field} !== null) {
                    $resolved[$field] = ['value' => $row->{$field}, 'source' => $source];
                }
            }
        }

        return $resolved;
    }

    private static function instanceRow(): ?ImageScanPolicy
    {
        return ImageScanPolicy::query()->whereNull('scope_type')->orderBy('id')->first();
    }

    private static function scopeRow(Model $scope): ?ImageScanPolicy
    {
        return ImageScanPolicy::query()
            ->where('scope_type', $scope->getMorphClass())
            ->where('scope_id', $scope->getKey())
            ->first();
    }

    private static function isScanEnabled(Model $resource, ?Server $scanServer): bool
    {
        return self::offState($resource, $scanServer) === null;
    }

    /**
     * Why the resource is not scanned now: "server" (scanning is off for its server), "application" (the app turned
     * its scan off), or null when it is scanned. Every surface
     * that shows results (resource page, sidebar count, markers, team page, dashboard) uses this rule. Reads loaded
     * relations, so eager load the server settings (and the app settings) for lists.
     */
    public static function offState(Model $resource, ?Server $scanServer = null): ?string
    {
        $scanServer ??= self::mainServer($resource);

        return match (true) {
            ! $scanServer instanceof Server || ! $scanServer->settings?->is_image_scan_enabled => 'server',
            $resource instanceof Application && ! $resource->settings?->is_image_scan_enabled => 'application',
            default => null,
        };
    }

    /**
     * An app did not turn its scan off. Reads no rules and no server, so a caller can stop before it resolves them.
     */
    public static function isAllowedFor(Model $resource): bool
    {
        return ! $resource instanceof Application || (bool) $resource->settings?->is_image_scan_enabled;
    }

    public static function isScanOn(Model $resource): bool
    {
        return self::offState($resource) === null;
    }

    /**
     * The server whose scan settings apply: the destination server, or the server of a service.
     */
    public static function mainServerOf(Model $resource): ?Server
    {
        return self::mainServer($resource);
    }

    private static function mainServer(Model $resource): ?Server
    {
        $owner = match (true) {
            $resource instanceof ServiceApplication, $resource instanceof ServiceDatabase => $resource->service,
            default => $resource,
        };

        $server = $owner?->destination?->server;
        if (! $server instanceof Server && $owner instanceof Service) {
            $server = $owner->server;
        }

        return $server instanceof Server ? $server : null;
    }
}
