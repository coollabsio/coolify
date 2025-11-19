<?php

namespace App\Helpers;

class TerminalFileHelper
{
    /**
     * Generate a safe filename with embedded metadata
     *
     * Format: {uploadedAt}_{expiresAt}_{serverId}_{containerUuid}_{originalName}_{hash}.{ext}
     * Example: 1699900000_1699903600_123_abc123def456_demo-file_a1b2c3d4.txt
     */
    public static function generateFilename(
        string $originalFilename,
        int $expiresAt,
        int $serverId,
        ?string $containerUuid = null
    ): string {
        $extension = pathinfo($originalFilename, PATHINFO_EXTENSION);
        $nameWithoutExt = pathinfo($originalFilename, PATHINFO_FILENAME);

        // Create safe slug from original filename
        $safeSlug = \Illuminate\Support\Str::slug($nameWithoutExt);
        $safeSlug = substr($safeSlug, 0, 30); // Limit length

        // Sanitize extension (only allow alphanumeric)
        $safeExtension = preg_replace('/[^a-zA-Z0-9]/', '', $extension);

        // Generate hash for uniqueness
        $hash = \Illuminate\Support\Str::random(8);

        // Build filename parts
        $uploadedAt = time();
        $containerPart = $containerUuid ? substr($containerUuid, 0, 12) : 'nocontainer';

        // Format: uploadedAt_expiresAt_serverId_containerUuid_originalName_hash.ext
        return sprintf(
            '%d_%d_%d_%s_%s_%s%s',
            $uploadedAt,
            $expiresAt,
            $serverId,
            $containerPart,
            $safeSlug,
            $hash,
            $safeExtension ? '.' . $safeExtension : ''
        );
    }

    /**
     * Parse filename to extract metadata
     *
     * Returns array with: uploaded_at, expires_at, server_id, container_uuid, original_name, hash, extension
     * Returns null if filename doesn't match expected format
     */
    public static function parseFilename(string $filename): ?array
    {
        // Pattern: uploadedAt_expiresAt_serverId_containerUuid_originalName_hash.ext
        $pattern = '/^(\d+)_(\d+)_(\d+)_([^_]+)_([^_]+)_([a-zA-Z0-9]+)(?:\.([a-zA-Z0-9]+))?$/';

        if (!preg_match($pattern, $filename, $matches)) {
            return null;
        }

        return [
            'uploaded_at' => (int) $matches[1],
            'expires_at' => (int) $matches[2],
            'server_id' => (int) $matches[3],
            'container_uuid' => $matches[4] === 'nocontainer' ? null : $matches[4],
            'original_name' => str_replace('-', ' ', $matches[5]),
            'hash' => $matches[6],
            'extension' => $matches[7] ?? null,
        ];
    }

    /**
     * Generate server path for the uploaded file
     */
    public static function generateServerPath(string $filename): string
    {
        // Just use the filename directly - it already contains all metadata
        return "/tmp/{$filename}";
    }

    /**
     * Generate container path for the uploaded file
     */
    public static function generateContainerPath(string $filename): string
    {
        // Use same filename in container
        return "/tmp/{$filename}";
    }

    /**
     * Check if file is expired
     */
    public static function isExpired(string $filename): bool
    {
        $metadata = self::parseFilename($filename);

        if (!$metadata) {
            return false;
        }

        return time() > $metadata['expires_at'];
    }

    /**
     * Get display name from filename
     */
    public static function getDisplayName(string $filename): string
    {
        $metadata = self::parseFilename($filename);

        if (!$metadata) {
            return $filename;
        }

        $displayName = ucwords($metadata['original_name']);

        if ($metadata['extension']) {
            $displayName .= '.' . $metadata['extension'];
        }

        return $displayName;
    }
}
