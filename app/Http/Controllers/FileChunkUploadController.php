<?php

namespace App\Http\Controllers;

use App\Models\Server;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class FileChunkUploadController extends Controller
{
    /**
     * Receive a single chunk of a file upload.
     * Chunks are stored in storage/app/temp/chunks/{uploadId}/
     */
    public function uploadChunk(Request $request)
    {
        $request->validate([
            'chunk' => 'required|file|max:20480', // 20MB max per chunk
            'uploadId' => 'required|string|alpha_dash',
            'chunkIndex' => 'required|integer|min:0',
            'totalChunks' => 'required|integer|min:1',
            'fileName' => 'required|string',
        ]);

        $uploadId = $request->input('uploadId');
        $chunkIndex = $request->input('chunkIndex');
        $chunkDir = "temp/chunks/{$uploadId}";

        // Ensure chunk directory exists
        if (! Storage::disk('local')->exists($chunkDir)) {
            Storage::disk('local')->makeDirectory($chunkDir);
        }

        // Store the chunk
        $chunk = $request->file('chunk');
        $chunkPath = "{$chunkDir}/chunk_{$chunkIndex}";
        $chunk->storeAs($chunkDir, "chunk_{$chunkIndex}", 'local');

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

        $chunkDir = Storage::disk('local')->path("temp/chunks/{$uploadId}");
        $assembledPath = Storage::disk('local')->path("temp/{$uploadId}_{$fileName}");

        try {
            // Verify server access
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

            // Assemble chunks into final file
            $outFile = fopen($assembledPath, 'wb');
            if (! $outFile) {
                return response()->json(['success' => false, 'message' => 'Failed to create assembled file.'], 500);
            }

            for ($i = 0; $i < $totalChunks; $i++) {
                $chunkPath = "{$chunkDir}/chunk_{$i}";
                $chunkFile = fopen($chunkPath, 'rb');
                if (! $chunkFile) {
                    fclose($outFile);

                    return response()->json(['success' => false, 'message' => "Failed to read chunk {$i}"], 500);
                }
                while (! feof($chunkFile)) {
                    fwrite($outFile, fread($chunkFile, 8192));
                }
                fclose($chunkFile);
            }
            fclose($outFile);

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

            // Clean up local chunks and assembled file
            $this->cleanupUpload($uploadId, $assembledPath);

            return response()->json([
                'success' => true,
                'message' => 'File uploaded successfully.',
                'path' => $fullDestPath,
            ]);
        } catch (\Throwable $e) {
            // Clean up on error
            $this->cleanupUpload($uploadId, $assembledPath);

            return response()->json(['success' => false, 'message' => 'Upload failed: '.$e->getMessage()], 500);
        }
    }

    /**
     * Clean up temporary chunk files and assembled file.
     */
    private function cleanupUpload(string $uploadId, string $assembledPath): void
    {
        // Delete chunk directory
        Storage::disk('local')->deleteDirectory("temp/chunks/{$uploadId}");

        // Delete assembled file
        if (file_exists($assembledPath)) {
            unlink($assembledPath);
        }
    }
}
