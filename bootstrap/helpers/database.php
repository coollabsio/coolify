<?php

use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Models\Service;
use App\Models\ServiceDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

function updateResourceDatabases(Service|Application|ApplicationPreview $resource, Collection $detectedDatabases): void
{
    $resourceId = $resource->id;
    $column = match (true) {
        $resource instanceof Service => 'service_id',
        $resource instanceof Application => 'application_id',
        $resource instanceof ApplicationPreview => 'application_preview_id',
    };

    DB::transaction(function () use ($column, $detectedDatabases, $resourceId): void {
        $existingDatabases = ServiceDatabase::where($column, $resourceId)->get();

        $processedNames = [];

        foreach ($detectedDatabases as $serviceName => $serviceConfig) {
            $image = data_get($serviceConfig, 'image');
            $ports = data_get($serviceConfig, 'ports', []);

            $collectedPorts = collect($ports)->map(function ($sport) {
                if (is_string($sport) || is_numeric($sport)) {
                    return $sport;
                }
                if (is_array($sport)) {
                    $target = data_get($sport, 'target');
                    $published = data_get($sport, 'published');
                    $protocol = data_get($sport, 'protocol', 'tcp');

                    return $published ? "$target:$published/$protocol" : "$target/$protocol";
                }

                return null;
            })->filter()->implode(',');

            $databaseRecord = $existingDatabases->where('name', $serviceName)->first();

            $data = [
                'name' => $serviceName,
                'image' => $image,
                'ports' => $collectedPorts,
                $column => $resourceId,
            ];

            if ($databaseRecord) {
                $databaseRecord->update($data);
            } else {
                ServiceDatabase::create($data);
            }

            $processedNames[] = $serviceName;
        }

        foreach ($existingDatabases as $existingDb) {
            if (! in_array($existingDb->name, $processedNames, true)) {
                $existingDb->delete();
            }
        }
    });
}
