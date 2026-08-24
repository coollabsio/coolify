<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Actions\CoolifyTask\RunRemoteProcess;
use App\Actions\Database\StartDatabaseImport;
use App\Support\DatabaseBackupFileValidator;
use App\Support\DatabaseImport\DatabaseImportException;
use App\Support\DatabaseImport\DatabaseImportSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Pion\Laravel\ChunkUpload\Handler\HandlerFactory;
use Pion\Laravel\ChunkUpload\Receiver\FileReceiver;
use Spatie\Activitylog\Models\Activity;

trait HandlesDatabaseImportsApi
{
    protected function uploadDatabaseImport(Request $request, Model $resource, int $teamId): JsonResponse
    {
        $this->authorize('uploadBackup', $resource);
        $validator = Validator::make($request->all(), ['upload_id' => ['required', 'uuid'], 'file' => ['required', 'file']]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed.', 'errors' => $validator->errors()], 422);
        }
        $originalName = $request->file('file')?->getClientOriginalName();
        if (! $originalName || ! DatabaseBackupFileValidator::hasAllowedExtension($originalName)) {
            return response()->json(['message' => 'Validation failed.', 'errors' => ['file' => ['Unsupported backup file extension.']]], 422);
        }
        if ((int) $request->input('dzTotalFilesize', 0) > StartDatabaseImport::MAX_BYTES) {
            return response()->json(['message' => 'Validation failed.', 'errors' => ['file' => ['The backup exceeds the 10 GiB limit.']]], 422);
        }

        $request->merge(['dzuuid' => $request->input('dzuuid', $request->string('upload_id')->value())]);
        $receiver = new FileReceiver('file', $request, HandlerFactory::classFromRequest($request));
        $save = $receiver->receive();
        if (! $save->isFinished()) {
            return response()->json(['upload_id' => $request->string('upload_id')->value(), 'done' => $save->handler()->getPercentageDone(), 'status' => true]);
        }

        $file = $save->getFile();
        if (! $file instanceof UploadedFile || ! DatabaseBackupFileValidator::isUploadAllowed($file, StartDatabaseImport::MAX_BYTES)) {
            @unlink($file->getPathname());

            return response()->json(['message' => 'Validation failed.', 'errors' => ['file' => ['Uploaded file failed validation.']]], 422);
        }
        $mimeType = $file->getMimeType();
        $size = $file->getSize();
        $directory = "upload/imports/{$teamId}/{$resource->uuid}/{$request->string('upload_id')->value()}";
        Storage::makeDirectory($directory);
        $file->move(Storage::path($directory), 'restore');

        return response()->json(['upload_id' => $request->string('upload_id')->value(), 'filename' => $originalName, 'mime_type' => $mimeType, 'size' => $size], 201);
    }

    protected function startDatabaseImport(Request $request, Model $resource, int $teamId, string $statusRoute, array $routeParameters): JsonResponse
    {
        $this->authorize('update', $resource);
        $payload = $request->json()->all() ?: $request->request->all();
        $allowed = ['source', 'upload_id', 's3_storage_uuid', 'path', 'dump_all'];
        $validator = Validator::make($payload, [
            'source' => ['required', Rule::in(['upload', 's3', 'server'])],
            'upload_id' => ['required_if:source,upload', 'prohibited_unless:source,upload', 'uuid'],
            's3_storage_uuid' => ['required_if:source,s3', 'prohibited_unless:source,s3', 'string'],
            'path' => ['required_if:source,s3,server', 'prohibited_if:source,upload', 'string', 'max:4096'],
            'dump_all' => ['sometimes', 'boolean'],
        ]);
        foreach (array_diff(array_keys($payload), $allowed) as $field) {
            $validator->errors()->add($field, 'This field is not allowed.');
        }
        if ($validator->fails() || $validator->errors()->isNotEmpty()) {
            return response()->json(['message' => 'Validation failed.', 'errors' => $validator->errors()], 422);
        }

        try {
            $source = new DatabaseImportSource((string) $payload['source'], $payload['upload_id'] ?? null, $payload['path'] ?? null, $payload['s3_storage_uuid'] ?? null, (bool) ($payload['dump_all'] ?? false));
            $activity = app(StartDatabaseImport::class)->handle($resource, $source, $teamId);
        } catch (DatabaseImportException $exception) {
            return response()->json(['message' => $exception->getMessage()], $exception->status);
        }
        $url = route($statusRoute, [...$routeParameters, 'activity_id' => $activity->id], false);

        return response()->json(['id' => $activity->id, 'status' => data_get($activity, 'properties.status'), 'message' => 'Database import queued.', 'status_url' => $url], 202)->header('Location', $url);
    }

    protected function showDatabaseImport(Model $resource, int $teamId, int $activityId): JsonResponse
    {
        $this->authorize('view', $resource);
        $activity = Activity::query()->whereKey($activityId)
            ->where('properties->team_id', $teamId)
            ->where('properties->type_uuid', $resource->uuid)
            ->where('properties->operation', 'database_import')->first();
        if (! $activity) {
            return response()->json(['message' => 'Database import not found.'], 404);
        }
        $status = data_get($activity, 'properties.status');
        $terminal = in_array($status, ['finished', 'error', 'killed', 'cancelled', 'closed'], true);

        return response()->json([
            'id' => $activity->id,
            'status' => $status,
            'exit_code' => data_get($activity, 'properties.exitCode'),
            'output' => remove_iip(RunRemoteProcess::decodeOutput($activity)),
            'created_at' => $activity->created_at,
            'updated_at' => $activity->updated_at,
            'finished_at' => $terminal ? $activity->updated_at : null,
        ]);
    }
}
