<?php

namespace App\Http\Controllers;

use App\Models\Server;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class FileChunkUploadController extends Controller
{
    /**
     * Receive a single chunk of a file upload.
     */
    public function uploadChunk(Request $request)
    {
        $request->validate([
            'chunk' => 'required|file',
            'uploadId' => 'required|string|alpha_dash',
            'chunkIndex' => 'required|integer|min:0',
            'totalChunks' => 'required|integer|min:1',
            'fileName' => 'required|string',
        ]);

        $uploadId = $request->input('uploadId');
        $chunkIndex = $request->input('chunkIndex');
        $chunkDir = storage_path("app/temp/chunks/{$uploadId}");

        if (! file_exists($chunkDir)) {
            @mkdir($chunkDir, 0755, true);
        }

        $chunk = $request->file('chunk');
        $chunkSize = $chunk->getSize();
        
        \Log::info("Chunk {$chunkIndex} for {$uploadId} received. Size: {$chunkSize} bytes.");
        
        $chunk->move($chunkDir, "chunk_{$chunkIndex}");

        return response()->json([
            'success' => true,
            'chunkIndex' => $chunkIndex,
        ]);
    }

    /**
     * Assemble all chunks into the final file and docker cp it into the container.
     */
    public function finalizeUpload(Request $request)
    {
        $request->validate([
            'uploadId' => 'required|string|alpha_dash',
            'totalChunks' => 'required|integer|min:1',
            'fileName' => 'required|string',
            'containerName' => 'required|string',
            'serverId' => 'required|integer',
            'destinationPath' => 'required|string',
        ]);

        $uploadId = $request->input('uploadId');
        $totalChunks = $request->input('totalChunks');
        $fileName = $request->input('fileName');
        $containerName = $request->input('containerName');
        $serverId = $request->input('serverId');
        $destinationPath = $request->input('destinationPath');

        $chunkDir = storage_path("app/temp/chunks/{$uploadId}");
        $assembledPath = storage_path("app/temp/{$uploadId}_{$fileName}");

        try {
            $server = Server::find($serverId);
            if (! $server || ! $server->isFunctional()) {
                return response()->json(['success' => false, 'message' => 'Server not found or not accessible.'], 404);
            }

            // Verify all chunks exist
            for ($i = 0; $i < $totalChunks; $i++) {
                $chunkPath = "{$chunkDir}/chunk_{$i}";
                if (! file_exists($chunkPath)) {
                    return response()->json(['success' => false, 'message' => "Missing chunk {$i}"], 400);
                }
            }

            // Assemble chunks into final file using streams
            $outFile = fopen($assembledPath, 'wb');
            if (! $outFile) {
                return response()->json(['success' => false, 'message' => 'Failed to create assembled file.'], 500);
            }

            $totalSize = 0;
            for ($i = 0; $i < $totalChunks; $i++) {
                $chunkPath = "{$chunkDir}/chunk_{$i}";
                $chunkFile = fopen($chunkPath, 'rb');
                while (! feof($chunkFile)) {
                    $buffer = fread($chunkFile, 1048576); // 1MB chunks
                    if ($buffer !== false && $buffer !== '') {
                        $totalSize += strlen($buffer);
                        fwrite($outFile, $buffer);
                    }
                }
                fclose($chunkFile);
                @unlink($chunkPath); // Free up disk space immediately
            }
            fclose($outFile);
            @rmdir($chunkDir);

            $actualSize = filesize($assembledPath);
            \Log::info("Assembled file {$fileName} size calculated: {$totalSize}, actual on disk: {$actualSize}");

            if ($actualSize === 0) {
                @unlink($assembledPath);
                return response()->json(['success' => false, 'message' => "Assembled file is empty (computed {$totalSize} bytes from chunks)."], 500);
            }

            // SCP assembled file to server /tmp
            $serverTmpPath = '/tmp/'.basename($assembledPath);
            instant_scp($assembledPath, $serverTmpPath, $server);

            // Docker cp from server tmp to container
            $escapedContainer = escapeshellarg($containerName);
            $fullDestPath = rtrim($destinationPath, '/').'/'.basename($fileName);
            $escapedDest = escapeshellarg($fullDestPath);
            $command = "docker cp {$serverTmpPath} {$escapedContainer}:{$escapedDest}";
            if ($server->isNonRoot()) {
                $command = "sudo {$command}";
            }
            instant_remote_process([$command], $server);

            // Clean up server tmp
            $cleanCmd = 'rm -f '.escapeshellarg($serverTmpPath);
            if ($server->isNonRoot()) {
                $cleanCmd = "sudo {$cleanCmd}";
            }
            instant_remote_process([$cleanCmd], $server, false);

            // Clean up local assembled file
            @unlink($assembledPath);

            return response()->json([
                'success' => true,
                'message' => 'File uploaded successfully.',
                'path' => $fullDestPath,
            ]);
        } catch (\Throwable $e) {
            @unlink($assembledPath);
            return response()->json(['success' => false, 'message' => 'Upload failed: '.$e->getMessage()], 500);
        }
    }
}
