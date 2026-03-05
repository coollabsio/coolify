<?php

namespace App\Jobs;

use App\Models\Server;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ExtractFileJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public $tries = 1;

    /**
     * The number of seconds the job can run before timing out.
     */
    public $timeout = 3600; // 1 hour for large files

    public function __construct(
        public Server $server,
        public string $containerName,
        public string $filePath,
        public bool $isNonRoot = false
    ) {
        $this->onQueue('high');
    }

    public function handle(): void
    {
        set_time_limit(0);
        ini_set('memory_limit', '-1');

        try {
            $escapedContainer = escapeshellarg($this->containerName);
            $innerCommand = "cd ".escapeshellarg(dirname($this->filePath))." && ";
            $fileNameEscaped = escapeshellarg(basename($this->filePath));

            if (str_ends_with(strtolower($this->filePath), '.zip')) {
                $innerCommand .= "if command -v unzip >/dev/null 2>&1; then unzip -q -o {$fileNameEscaped} -d . 2>&1 && echo 'EXTRACTION_SUCCESS'; else echo 'TOOL_NOT_FOUND:unzip'; fi";
            } elseif (preg_match('/\.(tar\.gz|tgz)$/i', $this->filePath)) {
                $innerCommand .= "if command -v tar >/dev/null 2>&1; then tar -xzf {$fileNameEscaped} -C . 2>&1 && echo 'EXTRACTION_SUCCESS'; else echo 'TOOL_NOT_FOUND:tar'; fi";
            } elseif (preg_match('/\.(tar\.bz2|tbz2|tbz)$/i', $this->filePath)) {
                $innerCommand .= "if command -v tar >/dev/null 2>&1; then tar -xjf {$fileNameEscaped} -C . 2>&1 && echo 'EXTRACTION_SUCCESS'; else echo 'TOOL_NOT_FOUND:tar'; fi";
            } elseif (preg_match('/\.(tar\.xz|txz)$/i', $this->filePath)) {
                $innerCommand .= "if command -v tar >/dev/null 2>&1; then tar -xJf {$fileNameEscaped} -C . 2>&1 && echo 'EXTRACTION_SUCCESS'; else echo 'TOOL_NOT_FOUND:tar'; fi";
            } elseif (str_ends_with(strtolower($this->filePath), '.tar')) {
                $innerCommand .= "if command -v tar >/dev/null 2>&1; then tar -xf {$fileNameEscaped} -C . 2>&1 && echo 'EXTRACTION_SUCCESS'; else echo 'TOOL_NOT_FOUND:tar'; fi";
            } elseif (str_ends_with(strtolower($this->filePath), '.gz')) {
                $innerCommand .= "if command -v gzip >/dev/null 2>&1; then gzip -d -k {$fileNameEscaped} 2>&1 && echo 'EXTRACTION_SUCCESS'; else echo 'TOOL_NOT_FOUND:gzip'; fi";
            } else {
                throw new \RuntimeException('Unsupported archive format');
            }

            $command = "docker exec {$escapedContainer} sh -c ".escapeshellarg($innerCommand);

            if ($this->isNonRoot) {
                $command = "sudo {$command}";
            }

            $output = trim(instant_remote_process([$command], $this->server, false) ?? '');

            if (str_contains($output, 'TOOL_NOT_FOUND:')) {
                preg_match('/TOOL_NOT_FOUND:(\w+)/', $output, $matches);
                $tool = $matches[1] ?? 'required tool';
                $this->extractViaHostFallback($tool);
            } elseif (! str_contains($output, 'EXTRACTION_SUCCESS')) {
                $errorMsg = str_replace('EXTRACTION_SUCCESS', '', $output);
                throw new \RuntimeException('Extraction failed: '.substr(trim($errorMsg), 0, 200));
            }

            Log::info('File extraction completed successfully', [
                'container' => $this->containerName,
                'file' => $this->filePath,
            ]);
        } catch (\Throwable $e) {
            Log::error('File extraction failed', [
                'container' => $this->containerName,
                'file' => $this->filePath,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function extractViaHostFallback(string $tool): void
    {
        $tmpBase = '/tmp/coolify_extract_'.uniqid();
        $hostArchivePath = $tmpBase.'_archive';
        $hostExtractPath = $tmpBase.'_extracted';

        $containerArchivePath = $this->filePath;
        $containerDestPath = dirname($this->filePath) === '/' ? '/' : dirname($this->filePath).'/';

        $fallbackCmds = [
            "mkdir -p ".escapeshellarg($hostExtractPath),
            "docker cp ".escapeshellarg($this->containerName.':'.$containerArchivePath)." ".escapeshellarg($hostArchivePath),
        ];

        if (str_ends_with(strtolower($this->filePath), '.zip')) {
            $fallbackCmds[] = "unzip -q -o ".escapeshellarg($hostArchivePath)." -d ".escapeshellarg($hostExtractPath);
        } elseif (preg_match('/\.(tar\.gz|tgz)$/i', $this->filePath)) {
            $fallbackCmds[] = "tar -xzf ".escapeshellarg($hostArchivePath)." -C ".escapeshellarg($hostExtractPath);
        } elseif (preg_match('/\.(tar\.bz2|tbz2|tbz)$/i', $this->filePath)) {
            $fallbackCmds[] = "tar -xjf ".escapeshellarg($hostArchivePath)." -C ".escapeshellarg($hostExtractPath);
        } elseif (preg_match('/\.(tar\.xz|txz)$/i', $this->filePath)) {
            $fallbackCmds[] = "tar -xJf ".escapeshellarg($hostArchivePath)." -C ".escapeshellarg($hostExtractPath);
        } elseif (str_ends_with(strtolower($this->filePath), '.tar')) {
            $fallbackCmds[] = "tar -xf ".escapeshellarg($hostArchivePath)." -C ".escapeshellarg($hostExtractPath);
        } elseif (str_ends_with(strtolower($this->filePath), '.gz')) {
            $extractedName = preg_replace('/\.gz$/i', '', basename($this->filePath));
            $fallbackCmds[] = "gzip -d -k -c ".escapeshellarg($hostArchivePath)." > ".escapeshellarg($hostExtractPath.'/'.$extractedName);
        }

        $fallbackCmds[] = "docker cp ".escapeshellarg($hostExtractPath.'/.')." ".escapeshellarg($this->containerName.':'.$containerDestPath);
        $fallbackCmds[] = "rm -rf ".escapeshellarg($hostArchivePath)." ".escapeshellarg($hostExtractPath);

        $fallbackCommandShell = implode(' && ', $fallbackCmds);
        $fallbackCommandShell .= " ; rm -rf ".escapeshellarg($hostArchivePath)." ".escapeshellarg($hostExtractPath);

        $finalCmd = $this->isNonRoot
            ? "sudo sh -c ".escapeshellarg($fallbackCommandShell)
            : "sh -c ".escapeshellarg($fallbackCommandShell);

        instant_remote_process([$finalCmd], $this->server, true);

        Log::info('File extracted via host fallback', [
            'container' => $this->containerName,
            'file' => $this->filePath,
            'tool' => $tool,
        ]);
    }
}
