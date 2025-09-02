<?php

namespace App\Http\Controllers;

use App\Http\Requests\FileOperationRequest;
use App\Services\ContainerFileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Response;

class FileBrowserController extends Controller
{
    protected ContainerFileService $fileService;

    public function __construct(ContainerFileService $fileService)
    {
        $this->fileService = $fileService;
    }

    /**
     * List files and directories in a container path
     */
    public function listFiles(Request $request, string $containerId): JsonResponse
    {
        try {
            $path = $request->get('path', '/');
            $showHidden = $request->boolean('show_hidden', false);

            $files = $this->fileService->listFiles($containerId, $path, $showHidden);

            return response()->json([
                'success' => true,
                'data' => $files,
                'path' => $path,
            ]);
        } catch (\Exception $e) {
            Log::error('File browser list error', [
                'container_id' => $containerId,
                'path' => $request->get('path'),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to list files: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Upload file to container
     */
    public function uploadFile(FileOperationRequest $request, string $containerId): JsonResponse
    {
        try {
            $file = $request->file('file');
            $targetPath = $request->get('path', '/');
            $permissions = $request->get('permissions', '644');

            $result = $this->fileService->uploadFile($containerId, $file, $targetPath, $permissions);

            return response()->json([
                'success' => true,
                'message' => 'File uploaded successfully',
                'data' => $result,
            ]);
        } catch (\Exception $e) {
            Log::error('File upload error', [
                'container_id' => $containerId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to upload file: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Download file from container
     */
    public function downloadFile(Request $request, string $containerId)
    {
        try {
            $filePath = $request->get('path');

            if (! $filePath) {
                return response()->json([
                    'success' => false,
                    'message' => 'File path is required',
                ], 400);
            }

            $fileContent = $this->fileService->downloadFile($containerId, $filePath);
            $fileName = basename($filePath);

            return Response::make($fileContent, 200, [
                'Content-Type' => 'application/octet-stream',
                'Content-Disposition' => 'attachment; filename="'.$fileName.'"',
            ]);
        } catch (\Exception $e) {
            Log::error('File download error', [
                'container_id' => $containerId,
                'file_path' => $request->get('path'),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to download file: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Create directory in container
     */
    public function createDirectory(FileOperationRequest $request, string $containerId): JsonResponse
    {
        try {
            $path = $request->get('path');
            $permissions = $request->get('permissions', '755');

            $this->fileService->createDirectory($containerId, $path, $permissions);

            return response()->json([
                'success' => true,
                'message' => 'Directory created successfully',
            ]);
        } catch (\Exception $e) {
            Log::error('Directory creation error', [
                'container_id' => $containerId,
                'path' => $request->get('path'),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create directory: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete file or directory from container
     */
    public function deleteItem(Request $request, string $containerId): JsonResponse
    {
        try {
            $path = $request->get('path');
            $isDirectory = $request->boolean('is_directory', false);

            $this->fileService->deleteItem($containerId, $path, $isDirectory);

            return response()->json([
                'success' => true,
                'message' => ($isDirectory ? 'Directory' : 'File').' deleted successfully',
            ]);
        } catch (\Exception $e) {
            Log::error('Item deletion error', [
                'container_id' => $containerId,
                'path' => $request->get('path'),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete item: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update file permissions
     */
    public function updatePermissions(FileOperationRequest $request, string $containerId): JsonResponse
    {
        try {
            $path = $request->get('path');
            $permissions = $request->get('permissions');
            $recursive = $request->boolean('recursive', false);

            $this->fileService->updatePermissions($containerId, $path, $permissions, $recursive);

            return response()->json([
                'success' => true,
                'message' => 'Permissions updated successfully',
            ]);
        } catch (\Exception $e) {
            Log::error('Permission update error', [
                'container_id' => $containerId,
                'path' => $request->get('path'),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update permissions: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get container volumes and mounts
     */
    public function getContainerMounts(string $containerId): JsonResponse
    {
        try {
            $mounts = $this->fileService->getContainerMounts($containerId);

            return response()->json([
                'success' => true,
                'data' => $mounts,
            ]);
        } catch (\Exception $e) {
            Log::error('Get mounts error', [
                'container_id' => $containerId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to get container mounts: '.$e->getMessage(),
            ], 500);
        }
    }
}
