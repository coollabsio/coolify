<?php

namespace App\Http\Controllers;

use App\Models\ImageScan;
use App\Services\ImageScan\ResourceScans;
use App\Services\ImageScan\SbomDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Downloads the SBOM of a current scan of a resource, made from the stored package list only (spec 9).
 */
class ImageScanSbomController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $scanUuid = (string) $request->route('scan_uuid');
        $format = (string) $request->route('format');
        abort_unless(in_array($format, SbomDocument::FORMATS, true), 404);

        [$type, $uuid] = match (true) {
            $request->route('application_uuid') !== null => ['application', (string) $request->route('application_uuid')],
            $request->route('service_uuid') !== null => ['service', (string) $request->route('service_uuid')],
            default => ['database', (string) $request->route('database_uuid')],
        };

        $user = $request->user();
        $resource = ResourceScans::find($type, $uuid, $user->teams->pluck('id')->all());
        abort_unless($resource !== null && data_get($resource, 'environment.uuid') === $request->route('environment_uuid'), 404);
        Gate::authorize('view', $resource);

        $scan = ResourceScans::current($resource)->first(fn (ImageScan $scan): bool => $scan->uuid === $scanUuid);
        $sbom = $scan?->sbom;
        abort_unless($scan !== null && $sbom !== null && $sbom->server_id === $scan->server_id, 404);

        $document = $format === 'cyclonedx' ? SbomDocument::cycloneDx($scan, $sbom) : SbomDocument::spdx($scan, $sbom);
        $name = Str::slug((string) $resource->getAttribute('name').'-'.$scan->image_ref) ?: 'image';
        $extension = $format === 'cyclonedx' ? 'cdx.json' : 'spdx.json';

        return response()->json($document, 200, [
            'Content-Disposition' => 'attachment; filename="'.$name.'-sbom.'.$extension.'"',
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }
}
