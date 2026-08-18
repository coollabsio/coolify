<?php

namespace App\Services;

use App\Models\S3Storage;
use App\Models\User;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class AvatarStorageService
{
    public function store(User $user, UploadedFile $upload): void
    {
        $settings = instanceSettings();
        $storageType = $settings->avatar_storage_type === 's3' && $settings->avatar_s3_storage_id ? 's3' : 'local';
        $s3StorageId = $storageType === 's3' ? $settings->avatar_s3_storage_id : null;
        $disk = $this->disk($storageType, $s3StorageId);
        $path = "avatars/{$user->id}/avatar.jpg";
        $contents = $this->compress($upload);

        if (! $disk->put($path, $contents)) {
            throw new RuntimeException('Unable to store the profile picture.');
        }

        $oldStorageType = $user->avatar_storage_type;
        $oldS3StorageId = $user->avatar_s3_storage_id;
        $oldPath = $user->avatar_path;

        $user->update([
            'avatar_path' => $path,
            'avatar_storage_type' => $storageType,
            'avatar_s3_storage_id' => $s3StorageId,
        ]);

        if ($oldPath && ($oldStorageType !== $storageType || $oldS3StorageId !== $s3StorageId)) {
            $this->disk($oldStorageType ?? 'local', $oldS3StorageId)->delete($oldPath);
        }
    }

    public function contents(User $user): ?string
    {
        if (! $user->avatar_path) {
            return null;
        }

        try {
            $disk = $this->disk($user->avatar_storage_type ?? 'local', $user->avatar_s3_storage_id);
        } catch (RuntimeException) {
            return null;
        }

        return $disk->exists($user->avatar_path) ? $disk->get($user->avatar_path) : null;
    }

    public function delete(User $user): void
    {
        if ($user->avatar_path) {
            $this->disk($user->avatar_storage_type ?? 'local', $user->avatar_s3_storage_id)
                ->delete($user->avatar_path);
        }

        $user->update([
            'avatar_path' => null,
            'avatar_storage_type' => null,
            'avatar_s3_storage_id' => null,
        ]);
    }

    protected function disk(string $storageType, ?int $s3StorageId): FilesystemAdapter
    {
        if ($storageType !== 's3') {
            return Storage::disk('local');
        }

        $storage = S3Storage::query()->whereKey($s3StorageId)->where('is_usable', true)->first();
        if (! $storage) {
            throw new RuntimeException('The configured S3 storage is not available.');
        }

        return $storage->filesystem();
    }

    protected function compress(UploadedFile $upload): string
    {
        $imageInfo = getimagesize($upload->getRealPath());
        if ($imageInfo && $imageInfo['mime'] === 'image/jpeg' && $imageInfo[0] <= 256 && $imageInfo[1] <= 256) {
            return file_get_contents($upload->getRealPath());
        }

        if (extension_loaded('imagick')) {
            $image = new \Imagick($upload->getRealPath());
            $image->setIteratorIndex(0);
            $image->autoOrient();
            $image->cropThumbnailImage(256, 256);
            $image->stripImage();
            $image->setImageBackgroundColor('white');
            $image = $image->mergeImageLayers(\Imagick::LAYERMETHOD_FLATTEN);
            $image->setImageFormat('jpeg');
            $image->setImageCompressionQuality(80);
            $contents = $image->getImagesBlob();
            $image->clear();

            return $contents;
        }

        if (! function_exists('imagecreatefromstring') || ! function_exists('imagejpeg')) {
            throw new RuntimeException('ImageMagick or GD is required to process profile pictures that were not compressed by the browser.');
        }

        $source = imagecreatefromstring(file_get_contents($upload->getRealPath()));
        if (! $source) {
            throw new RuntimeException('Unable to read the uploaded profile picture.');
        }

        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        $cropSize = min($sourceWidth, $sourceHeight);
        $target = imagecreatetruecolor(256, 256);
        imagefill($target, 0, 0, imagecolorallocate($target, 255, 255, 255));
        imagecopyresampled(
            $target,
            $source,
            0,
            0,
            (int) (($sourceWidth - $cropSize) / 2),
            (int) (($sourceHeight - $cropSize) / 2),
            256,
            256,
            $cropSize,
            $cropSize,
        );

        ob_start();
        imagejpeg($target, null, 80);
        $contents = ob_get_clean();
        imagedestroy($source);
        imagedestroy($target);

        if (! is_string($contents)) {
            throw new RuntimeException('Unable to compress the profile picture.');
        }

        return $contents;
    }
}
