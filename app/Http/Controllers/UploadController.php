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
    public function upload(Request $request)
    {
        $resource = getResourceByUuid(request()->route('databaseUuid'), data_get(auth()->user()->currentTeam(), 'id'));
        if (is_null($resource)) {
            return response()->json(['error' => 'You do not have permission for this database'], 500);
        }
        $receiver = new FileReceiver('file', $request, HandlerFactory::classFromRequest($request));

        if ($receiver->isUploaded() === false) {
            throw new UploadMissingFileException;
        }

        $save = $receiver->receive();

        if ($save->isFinished()) {
            return $this->saveFile($save->getFile(), $resource);
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
    protected function saveFile(UploadedFile $file, $resource)
    {
        $mime = str_replace('/', '-', $file->getMimeType());
        $filePath = "upload/{$resource->uuid}";
        $finalPath = storage_path('app/'.$filePath);
        $file->move($finalPath, 'restore');

        return response()->json([
            'mime_type' => $mime,
        ]);
    }

    protected function createFilename(UploadedFile $file)
    {
        $extension = $file->getClientOriginalExtension();
        $filename = str_replace('.'.$extension, '', $file->getClientOriginalName()); // Filename without extension

        $filename .= '_'.md5(time()).'.'.$extension;

        return $filename;
    }
}
