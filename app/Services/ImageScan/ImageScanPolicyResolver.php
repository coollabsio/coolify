<?php

namespace App\Services\ImageScan;

use App\Enums\ImageScanAlertLevel;
use App\Enums\ImageScanOnError;
use App\Models\Application;
use App\Models\ImageScanPolicy;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use Illuminate\Database\Eloquent\Model;

/**
 * Resolves scan rules through Instance, Team, Server and App. A null value inherits.
 * Team and server come from the loaded resource, never from the session.
 */
class ImageScanPolicyResolver
{
    private const FIELDS = ['alert_level', 'gate_enabled', 'on_scan_error'];

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

        $levels = [['instance', self::instanceRow()]];
        $team = method_exists($resource, 'team') ? $resource->team() : null;
        if ($team instanceof Model) {
            $levels[] = ['team', self::scopeRow($team)];
        }
        if ($mainServer instanceof Server) {
            $levels[] = ['server', self::scopeRow($mainServer)];
        }
        if ($isApplication) {
            $levels[] = ['app', self::scopeRow($resource)];
        }

        $resolved = self::resolveLevels($levels);
        $values = array_map(fn (array $field) => $field['value'], $resolved);
        $sources = array_map(fn (array $field) => $field['source'], $resolved);

        $gateEnabled = $isApplication && $values['gate_enabled'] === true;

        return new ResolvedImageScanPolicy(
            alertLevel: $values['alert_level'],
            gateEnabled: $gateEnabled,
            onScanError: $values['on_scan_error'],
            scanEnabled: self::isScanEnabled($resource, $scanServer),
            sources: $sources,
            scanServer: $scanServer,
        );
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
     * @return array{alert_level: array{value: ImageScanAlertLevel, source: string}, gate_enabled: array{value: bool, source: string}, on_scan_error: array{value: ImageScanOnError, source: string}}
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
     * @return array{alert_level: array{value: ImageScanAlertLevel, source: string}, gate_enabled: array{value: bool, source: string}, on_scan_error: array{value: ImageScanOnError, source: string}}
     */
    private static function resolveLevels(array $levels): array
    {
        $resolved = [
            'alert_level' => ['value' => ImageScanAlertLevel::Critical, 'source' => 'instance'],
            'gate_enabled' => ['value' => false, 'source' => 'instance'],
            'on_scan_error' => ['value' => ImageScanOnError::Deploy, 'source' => 'instance'],
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
     * Why the resource is not scanned now: "instance" (the instance does not allow scanning), "server" (scanning is
     * off for its server), "application" (the app turned its scan off), or null when it is scanned. Every surface
     * that shows results (resource page, sidebar count, markers, team page, dashboard) uses this rule. Reads loaded
     * relations, so eager load the server settings (and the app settings) for lists.
     */
    public static function offState(Model $resource, ?Server $scanServer = null): ?string
    {
        $scanServer ??= self::mainServer($resource);

        return match (true) {
            ! InstanceSettings::get()->is_image_scanning_allowed => 'instance',
            ! $scanServer instanceof Server || ! $scanServer->settings?->is_image_scan_enabled => 'server',
            $resource instanceof Application && ! $resource->settings?->is_image_scan_enabled => 'application',
            default => null,
        };
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
