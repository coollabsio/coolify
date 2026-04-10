<?php

namespace App\Http\Controllers;

use App\Enums\TerminalUploadedFileStatus;
use App\Models\TerminalUploadedFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Pion\Laravel\ChunkUpload\Exceptions\UploadMissingFileException;
use Pion\Laravel\ChunkUpload\Handler\HandlerFactory;
use Pion\Laravel\ChunkUpload\Receiver\FileReceiver;

class UploadController extends BaseController
{
    public function upload(Request $request): JsonResponse
    {
        $databaseIdentifier = request()->route('databaseUuid');
        $resource = getResourceByUuid($databaseIdentifier, data_get(auth()->user()->currentTeam(), 'id'));
        if (is_null($resource)) {
            return response()->json(['error' => 'You do not have permission for this database'], 500);
        }
        $receiver = new FileReceiver('file', $request, HandlerFactory::classFromRequest($request));

        if ($receiver->isUploaded() === false) {
            throw new UploadMissingFileException;
        }

        $save = $receiver->receive();

        if ($save->isFinished()) {
            return $this->saveFile($save->getFile(), $databaseIdentifier);
        }

        $handler = $save->handler();

        return response()->json([
            'done' => $handler->getPercentageDone(),
            'status' => true,
        ]);
    }

    protected function saveFile(UploadedFile $file, string $resourceIdentifier): JsonResponse
    {
        $mime = str_replace('/', '-', (string) $file->getMimeType());
        $filePath = "upload/{$resourceIdentifier}";
        $finalPath = storage_path('app/'.$filePath);
        $file->move($finalPath, 'restore');

        return response()->json([
            'mime_type' => $mime,
        ]);
    }

    public function uploadTerminalFile(Request $request): JsonResponse
    {
        if (! Auth::check()) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        Gate::authorize('canAccessTerminal');

        $receiver = new FileReceiver('file', $request, HandlerFactory::classFromRequest($request));

        if ($receiver->isUploaded() === false) {
            throw new UploadMissingFileException;
        }

        $save = $receiver->receive();

        if ($save->isFinished()) {
            return $this->saveTerminalFile($save->getFile(), $request);
        }

        $handler = $save->handler();

        return response()->json([
            'done' => $handler->getPercentageDone(),
            'status' => true,
        ]);
    }

    protected function saveTerminalFile(UploadedFile $file, Request $request): JsonResponse
    {
        $user = Auth::user();
        $teamId = data_get($user?->currentTeam(), 'id');

        if (blank($teamId)) {
            return response()->json(['error' => 'No active team found.'], 422);
        }

        $mime = str_replace('/', '-', (string) $file->getMimeType());
        $size = (int) $file->getSize();
        $originalName = $file->getClientOriginalName();
        $extension = $file->getClientOriginalExtension();

        $uploadToken = (string) $request->input('resumableIdentifier', Str::uuid()->toString());
        $uploadToken = preg_replace('/[^a-zA-Z0-9_-]/', '', $uploadToken) ?: Str::uuid()->toString();

        $filePath = sprintf('terminal-uploads-pending/user_%d/%s', $user->id, $uploadToken);
        $finalPath = storage_path('app/'.$filePath);

        if (! is_dir($finalPath)) {
            mkdir($finalPath, 0755, true);
        }

        $nameWithoutExt = pathinfo($originalName, PATHINFO_FILENAME);
        $safeSlug = Str::slug($nameWithoutExt);
        $safeSlug = substr($safeSlug, 0, 50);
        $safeExtension = preg_replace('/[^a-zA-Z0-9]/', '', $extension);
        $randomHash = Str::random(16);
        $safeFilename = time().'_'.$safeSlug.'_'.$randomHash.($safeExtension ? '.'.$safeExtension : '');

        $file->move($finalPath, $safeFilename);

        $localPath = $finalPath.'/'.$safeFilename;

        $terminalUploadedFile = TerminalUploadedFile::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'team_id' => $teamId,
                'upload_token' => $uploadToken,
            ],
            [
                'original_name' => $originalName,
                'stored_filename' => $safeFilename,
                'mime_type' => $mime,
                'size_bytes' => $size,
                'local_path' => $localPath,
                'server_id' => null,
                'server_path' => null,
                'container_uuid' => null,
                'container_path' => null,
                'status' => TerminalUploadedFileStatus::Pending,
                'uploaded_at' => now(),
                'expires_at' => null,
                'finalized_at' => null,
                'deleted_at' => null,
                'last_cleanup_error' => null,
            ],
        );

        return response()->json([
            'done' => 100,
            'status' => true,
            'file_uuid' => $terminalUploadedFile->uuid,
            'mime_type' => $mime,
            'size' => $size,
            'upload_token' => $uploadToken,
            'stored_filename' => $safeFilename,
            'original_name' => $originalName,
        ]);
    }
}
