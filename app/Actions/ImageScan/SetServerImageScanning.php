<?php

namespace App\Actions\ImageScan;

use App\Jobs\PrepareImageScannerJob;
use App\Models\Server;
use App\Services\ImageScan\ImageScanningRefused;
use Illuminate\Support\Facades\Cache;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Turns image scanning of a server on or off. Turning it on gets the scanner on the server.
 */
class SetServerImageScanning
{
    use AsAction;

    /**
     * @throws ImageScanningRefused When another instance manages the server.
     */
    public function handle(Server $server, bool $enabled): void
    {
        if (! $enabled) {
            $server->settings->update(['is_image_scan_enabled' => false]);
            PrepareImageScannerJob::forget($server);

            return;
        }

        self::ensureManagedHere($server);
        $server->settings->update(['is_image_scan_enabled' => true]);
        self::prepareScanner($server);
    }

    /**
     * Gets the scanner on the server again, for a server that scans images.
     */
    public static function prepareScanner(Server $server): void
    {
        Cache::put(PrepareImageScannerJob::stateCacheKey($server), PrepareImageScannerJob::STATE_PULLING, now()->addHour());
        PrepareImageScannerJob::dispatch($server);
    }

    public static function ensureManagedHere(Server $server): void
    {
        if ($server->isTransferredAway()) {
            throw new ImageScanningRefused('This server is managed by another Coolify instance.');
        }
    }
}
