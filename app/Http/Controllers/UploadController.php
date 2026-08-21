<?php

namespace App\Http\Controllers;

use App\Support\DatabaseBackupFileValidator;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Pion\Laravel\ChunkUpload\Exceptions\UploadMissingFileException;
use Pion\Laravel\ChunkUpload\Handler\HandlerFactory;
use Pion\Laravel\ChunkUpload\Receiver\FileReceiver;

class UploadController extends BaseController
{
    use AuthorizesRequests;

    private const MAX_BYTES = 10 * 1024 * 1024 * 1024; // 10 GiB

    private const ALLOWED_EXTENSIONS = DatabaseBackupFileValidator::ALLOWED_EXTENSIONS;

    public function upload(Request $request)
    {
        $databaseIdentifier = request()->route('databaseUuid');
        $resource = getResourceByUuid($databaseIdentifier, data_get(auth()->user()->currentTeam(), 'id'));
        if (is_null($resource)) {
            return response()->json(['error' => 'You do not have permission for this database'], 500);
        }

        $this->authorize('uploadBackup', $resource);

        $chunk = $request->file('file');
        $originalName = $chunk instanceof UploadedFile ? $chunk->getClientOriginalName() : null;
        if (blank($originalName) || ! self::hasAllowedExtension($originalName)) {
            return response()->json([
                'error' => 'Unsupported file type. Allowed extensions: '.implode(', ', self::ALLOWED_EXTENSIONS),
            ], 422);
        }

        $declaredTotalSize = (int) $request->input('dzTotalFilesize', 0);
        if ($declaredTotalSize > self::MAX_BYTES) {
            return response()->json([
                'error' => 'File exceeds maximum allowed size of '.self::formatMaxSize().'.',
            ], 422);
        }

        $receiver = new FileReceiver('file', $request, HandlerFactory::classFromRequest($request));

        if ($receiver->isUploaded() === false) {
            throw new UploadMissingFileException();
        }

        $save = $receiver->receive();

        if ($save->isFinished()) {
            // Use the original identifier from the route to maintain path consistency
            // For ServiceDatabase: {name}-{service_uuid}
            // For standalone databases: {uuid}
            return $this->saveFile($save->getFile(), $databaseIdentifier);
        }

        $handler = $save->handler();

        return response()->json([
            'done' => $handler->getPercentageDone(),
            'status' => true,
        ]);
    }

    protected function saveFile(UploadedFile $file, string $resourceIdentifier)
    {
        if (! DatabaseBackupFileValidator::isUploadAllowed($file, self::MAX_BYTES)) {
            @unlink($file->getPathname());

            return response()->json([
                'error' => 'Uploaded file failed validation.',
            ], 422);
        }

        $mime = str_replace('/', '-', $file->getMimeType());
        $filePath = "upload/{$resourceIdentifier}";
        $finalPath = storage_path('app/'.$filePath);
        $file->move($finalPath, 'restore');

        return response()->json([
            'mime_type' => $mime,
        ]);
    }

    public function uploadTerminalFile(Request $request)
    {
        // Security: Verify user has permission to upload terminal files
        if (! Auth::check()) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        // Check if user is admin or has terminal access
        $user = Auth::user();
        if (! $user->isAdmin() && ! $user->isInstanceAdmin()) {
            return response()->json(['error' => 'You do not have permission to upload terminal files'], 403);
        }

        $receiver = new FileReceiver('file', $request, HandlerFactory::classFromRequest($request));

        if ($receiver->isUploaded() === false) {
            throw new UploadMissingFileException();
        }

        $save = $receiver->receive();

        if ($save->isFinished()) {
            return $this->saveTerminalFile($save->getFile());
        }

        $handler = $save->handler();

        return response()->json([
            'done' => $handler->getPercentageDone(),
            'status' => true,
        ]);
    }

    protected function saveTerminalFile(UploadedFile $file)
    {
        $mime = str_replace('/', '-', $file->getMimeType());
        $filePath = 'terminal-uploads/temp';
        $finalPath = storage_path('app/'.$filePath);

        // Create directory if it doesn't exist
        if (! is_dir($finalPath)) {
            mkdir($finalPath, 0755, true);
        }

        // Security: Generate safe filename server-side to prevent path traversal
        $originalName = $file->getClientOriginalName();
        $extension = $file->getClientOriginalExtension();

        // Create a safe slug from original filename (without extension)
        $nameWithoutExt = pathinfo($originalName, PATHINFO_FILENAME);
        $safeSlug = Str::slug($nameWithoutExt); // Converts to lowercase, replaces special chars with dashes
        $safeSlug = substr($safeSlug, 0, 50); // Limit length

        // Sanitize extension (only allow alphanumeric)
        $safeExtension = preg_replace('/[^a-zA-Z0-9]/', '', $extension);

        // Generate safe filename: timestamp_slug_randomhash.ext
        $randomHash = Str::random(16);
        $safeFilename = time().'_'.$safeSlug.'_'.$randomHash.($safeExtension ? '.'.$safeExtension : '');

        $file->move($finalPath, $safeFilename);

        return response()->json([
            'mime_type' => $mime,
            'filename' => $safeFilename,
            'original_name' => $originalName, // Keep original name for reference
        ]);
    }

    private static function hasAllowedExtension(string $name): bool
    {
        return DatabaseBackupFileValidator::hasAllowedExtension($name);
    }

    private static function formatMaxSize(): string
    {
        return (self::MAX_BYTES / (1024 * 1024 * 1024)).' GiB';
    }
}
