<?php

use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Models\Service;
use App\Models\ServiceDatabase;
use Illuminate\Support\Collection;

function updateResourceDatabases(Service|Application|ApplicationPreview $resource, Collection $detectedDatabases)
{
    $resourceType = $resource->getMorphClass();
    $resourceId = $resource->id;

    $existingDatabases = ServiceDatabase::where(function ($query) use ($resourceType, $resourceId) {
        if ($resourceType === Service::class) {
            $query->where('service_id', $resourceId);
        } elseif ($resourceType === Application::class) {
            $query->where('application_id', $resourceId);
        } elseif ($resourceType === ApplicationPreview::class) {
            $query->where('application_preview_id', $resourceId);
        }
    })->get();

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
        ];

        if ($resourceType === Service::class) {
            $data['service_id'] = $resourceId;
        } elseif ($resourceType === Application::class) {
            $data['application_id'] = $resourceId;
        } elseif ($resourceType === ApplicationPreview::class) {
            $data['application_preview_id'] = $resourceId;
        }

        if ($databaseRecord) {
            $databaseRecord->update($data);
        } else {
            ServiceDatabase::create($data);
        }

        $processedNames[] = $serviceName;
    }

    foreach ($existingDatabases as $existingDb) {
        if (!in_array($existingDb->name, $processedNames)) {
            $existingDb->delete();
        }
    }
}
