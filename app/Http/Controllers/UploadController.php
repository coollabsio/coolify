<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Controller as BaseController;
use Pion\Laravel\ChunkUpload\Exceptions\UploadMissingFileException;
use Pion\Laravel\ChunkUpload\Handler\HandlerFactory;
use Pion\Laravel\ChunkUpload\Receiver\FileReceiver;

class UploadController extends BaseController
{
    private const MAX_BYTES = 10 * 1024 * 1024 * 1024; // 10 GiB

    private const ALLOWED_EXTENSIONS = [
        'sql',
        'sql.gz',
        'gz',
        'zip',
        'tar',
        'tar.gz',
        'tgz',
        'dump',
        'bak',
        'bson',
        'bson.gz',
        'archive',
        'archive.gz',
        'bz2',
        'xz',
        'dmp',
    ];

    public function upload(Request $request)
    {
        $databaseIdentifier = request()->route('databaseUuid');
        $resource = getResourceByUuid($databaseIdentifier, data_get(auth()->user()->currentTeam(), 'id'));
        if (is_null($resource)) {
            return response()->json(['error' => 'You do not have permission for this database'], 500);
        }

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
            throw new UploadMissingFileException;
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
        $originalName = $file->getClientOriginalName();
        $size = $file->getSize();

        if (! self::hasAllowedExtension($originalName) || $size === false || $size > self::MAX_BYTES) {
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

    private static function hasAllowedExtension(string $name): bool
    {
        $lower = strtolower($name);
        $suffixes = array_map(fn ($ext) => '.'.$ext, self::ALLOWED_EXTENSIONS);
        usort($suffixes, fn ($a, $b) => strlen($b) <=> strlen($a));

        foreach ($suffixes as $suffix) {
            if (! str_ends_with($lower, $suffix)) {
                continue;
            }

            $stem = substr($lower, 0, -strlen($suffix));
            if ($stem !== '' && ! str_ends_with($stem, '.')) {
                return true;
            }

            return false;
        }

        return false;
    }

    private static function formatMaxSize(): string
    {
        return (self::MAX_BYTES / (1024 * 1024 * 1024)).' GiB';
    }
}
