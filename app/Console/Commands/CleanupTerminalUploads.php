<?php

namespace App\Console\Commands;

use App\Actions\Terminal\DeleteTerminalUploadedFile;
use App\Models\TerminalUploadedFile;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

class CleanupTerminalUploads extends Command
{
    protected $signature = 'cleanup:terminal-uploads {--pending-hours=24} {--chunk=100}';

    protected $description = 'Clean up expired and stale terminal uploads.';

    public function handle(DeleteTerminalUploadedFile $deleteTerminalUploadedFile): int
    {
        $chunkSize = max((int) $this->option('chunk'), 1);
        $pendingHours = max((int) $this->option('pending-hours'), 1);

        $expiredCount = 0;
        $stalePendingCount = 0;

        TerminalUploadedFile::query()
            ->expiredForCleanup()
            ->orderBy('id')
            ->chunkById($chunkSize, function (Collection $files) use ($deleteTerminalUploadedFile, &$expiredCount): void {
                foreach ($files as $file) {
                    try {
                        $deleteTerminalUploadedFile($file);
                        $expiredCount++;
                    } catch (\Throwable $e) {
                        $this->warn("Failed to clean expired terminal upload {$file->uuid}: {$e->getMessage()}");
                    }
                }
            });

        TerminalUploadedFile::query()
            ->pendingForCleanup($pendingHours)
            ->orderBy('id')
            ->chunkById($chunkSize, function (Collection $files) use ($deleteTerminalUploadedFile, &$stalePendingCount): void {
                foreach ($files as $file) {
                    try {
                        $deleteTerminalUploadedFile($file);
                        $stalePendingCount++;
                    } catch (\Throwable $e) {
                        $this->warn("Failed to clean pending terminal upload {$file->uuid}: {$e->getMessage()}");
                    }
                }
            });

        $this->info("Cleaned {$expiredCount} expired terminal uploads.");
        $this->info("Cleaned {$stalePendingCount} stale pending terminal uploads.");

        return self::SUCCESS;
    }
}
