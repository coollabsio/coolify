<?php

namespace App\Http\Controllers;

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
    public function upload(Request $request)
    {
        $databaseIdentifier = request()->route('databaseUuid');
        $resource = getResourceByUuid($databaseIdentifier, data_get(auth()->user()->currentTeam(), 'id'));
        if (is_null($resource)) {
            return response()->json(['error' => 'You do not have permission for this database'], 500);
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
    // protected function saveFileToS3($file)
    // {
    //     $fileName = $this->createFilename($file);

    //     $disk = Storage::disk('s3');
    //     // It's better to use streaming Streaming (laravel 5.4+)
    //     $disk->putFileAs('photos', $file, $fileName);

    //     // for older laravel
    //     // $disk->put($fileName, file_get_contents($file), 'public');
    //     $mime = str_replace('/', '-', $file->getMimeType());

    //     // We need to delete the file when uploaded to s3
    //     unlink($file->getPathname());

    //     return response()->json([
    //         'path' => $disk->url($fileName),
    //         'name' => $fileName,
    //         'mime_type' => $mime
    //     ]);
    // }
    protected function saveFile(UploadedFile $file, string $resourceIdentifier)
    {
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
}
