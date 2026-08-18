<?php

namespace App\Jobs;

use App\Models\Server;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

class CleanupStaleMultiplexedConnections implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle()
    {
        $this->cleanupStaleConnections();
        $this->cleanupNonExistentServerConnections();
        $this->cleanupOrphanedSshProcesses();
        $this->cleanupOrphanedCloudflaredProcesses();
    }

    /**
     * Kill backgrounded ssh master processes that lost the ControlPath socket
     * race. Such processes are not masters, so ControlPersist never reaps them
     * and they leak memory until the container restarts. A legitimate master
     * always owns its socket file; an orphan has none.
     *
     * Processes younger than the minimum age are skipped: a freshly forked
     * master creates its socket a few milliseconds after starting, so a young
     * process with no socket may simply be mid-establish rather than orphaned.
     */
    private function cleanupOrphanedSshProcesses(): void
    {
        $muxDir = storage_path('app/ssh/mux');
        $minAge = (int) config('constants.ssh.mux_orphan_min_age');

        foreach ($this->listProcesses() as $process) {
            // Backgrounded ssh master: current `ssh -fN` or legacy `ssh -fNM`.
            if (! preg_match('#(^|/)ssh -fN#', $process['args'])) {
                continue;
            }

            // Only ever touch ssh processes pointing at Coolify's mux directory.
            if (! preg_match('#ControlPath=('.preg_quote($muxDir, '#').'/\S+)#', $process['args'], $pathMatch)) {
                continue;
            }

            if ($process['etimes'] >= $minAge && ! file_exists($pathMatch[1])) {
                $this->reapOrphan('ssh', $process);
            }
        }
    }

    /**
     * Kill orphaned `cloudflared access ssh` proxy processes. Each is spawned
     * as the SSH ProxyCommand transport for a Cloudflare Tunnel server and must
     * die with its parent ssh. When that ssh is killed or orphaned (e.g. a lost
     * mux master), the cloudflared process can leak and accumulate. A legitimate
     * proxy always has a live ssh parent; one without is safe to reap.
     *
     * Processes younger than the minimum age are skipped so a proxy whose parent
     * ssh is still starting up, or a transient `ssh -O check` proxy mid-exit, is
     * never mistaken for an orphan.
     */
    private function cleanupOrphanedCloudflaredProcesses(): void
    {
        $minAge = (int) config('constants.ssh.mux_orphan_min_age');
        $processes = $this->listProcesses();

        $sshPids = [];
        foreach ($processes as $process) {
            // The ssh binary itself, not `cloudflared access ssh` (space before ssh).
            if (preg_match('#(^|/)ssh\s#', $process['args'])) {
                $sshPids[$process['pid']] = true;
            }
        }

        foreach ($processes as $process) {
            // `cloudflared access ssh`, never the `cloudflared tunnel` daemon.
            if (! str_contains($process['args'], 'cloudflared access ssh')) {
                continue;
            }

            // Orphaned when no live ssh process is its parent.
            if ($process['etimes'] >= $minAge && ! isset($sshPids[$process['ppid']])) {
                $this->reapOrphan('cloudflared', $process);
            }
        }
    }

    /**
     * Reap a detected orphan process. When orphan reaping is disabled (the
     * default), the orphan is only logged — a dry-run mode that lets operators
     * verify what would be killed before enabling it for real.
     *
     * @param  array{pid: string, ppid: string, etimes: int, args: string}  $process
     */
    private function reapOrphan(string $kind, array $process): void
    {
        if (! config('constants.ssh.mux_orphan_reap_enabled')) {
            Log::info("Orphaned {$kind} process detected (dry-run, not killed)", [
                'pid' => $process['pid'],
                'etimes' => $process['etimes'],
                'command' => $process['args'],
            ]);

            return;
        }

        Process::run('kill '.escapeshellarg($process['pid']));
        Log::info("Killed orphaned {$kind} process", [
            'pid' => $process['pid'],
            'etimes' => $process['etimes'],
            'command' => $process['args'],
        ]);
    }

    /**
     * Snapshot of running processes.
     *
     * @return list<array{pid: string, ppid: string, etimes: int, args: string}>
     */
    private function listProcesses(): array
    {
        $ps = Process::run('ps -ww -eo pid=,ppid=,etimes=,args=');
        if ($ps->exitCode() !== 0) {
            return [];
        }

        $processes = [];
        foreach (explode("\n", trim($ps->output())) as $line) {
            if (! preg_match('/^\s*(\d+)\s+(\d+)\s+(\d+)\s+(.*)$/', $line, $matches)) {
                continue;
            }
            $processes[] = [
                'pid' => $matches[1],
                'ppid' => $matches[2],
                'etimes' => (int) $matches[3],
                'args' => $matches[4],
            ];
        }

        return $processes;
    }

    private function cleanupStaleConnections()
    {
        $muxFiles = Storage::disk('ssh-mux')->files();

        foreach ($muxFiles as $muxFile) {
            $serverUuid = $this->extractServerUuidFromMuxFile($muxFile);
            $server = Server::where('uuid', $serverUuid)->first();

            if (! $server) {
                $this->removeMultiplexFile($muxFile, 'server_not_found');

                continue;
            }

            $muxSocket = "/var/www/html/storage/app/ssh/mux/{$muxFile}";
            $checkCommand = "ssh -O check -o ControlPath={$muxSocket} {$server->user}@{$server->ip} 2>/dev/null";
            $checkProcess = Process::run($checkCommand);

            if ($checkProcess->exitCode() !== 0) {
                $this->removeMultiplexFile($muxFile, 'connection_check_failed');
            } else {
                $muxContent = Storage::disk('ssh-mux')->get($muxFile);
                $establishedAt = Carbon::parse(substr($muxContent, 37));
                $expirationTime = $establishedAt->addSeconds(config('constants.ssh.mux_persist_time'));

                if (Carbon::now()->isAfter($expirationTime)) {
                    $this->removeMultiplexFile($muxFile, 'expired');
                }
            }
        }
    }

    private function cleanupNonExistentServerConnections()
    {
        $muxFiles = Storage::disk('ssh-mux')->files();
        $existingServerUuids = Server::pluck('uuid')->toArray();

        foreach ($muxFiles as $muxFile) {
            $serverUuid = $this->extractServerUuidFromMuxFile($muxFile);
            if (! in_array($serverUuid, $existingServerUuids)) {
                $this->removeMultiplexFile($muxFile, 'server_does_not_exist');
            }
        }
    }

    private function extractServerUuidFromMuxFile($muxFile)
    {
        return substr($muxFile, 4);
    }

    /**
     * Close and delete a stale mux socket file. When orphan reaping is disabled
     * (the default), the file is only logged — a dry-run mode that lets operators
     * verify what would be removed before enabling it for real.
     */
    private function removeMultiplexFile(string $muxFile, string $reason): void
    {
        if (! config('constants.ssh.mux_orphan_reap_enabled')) {
            Log::info('Stale mux file detected (dry-run, not removed)', [
                'file' => $muxFile,
                'reason' => $reason,
            ]);

            return;
        }

        $muxSocket = "/var/www/html/storage/app/ssh/mux/{$muxFile}";
        $closeCommand = "ssh -O exit -o ControlPath={$muxSocket} localhost 2>/dev/null";
        Process::run($closeCommand);
        Storage::disk('ssh-mux')->delete($muxFile);

        Log::info('Removed stale mux file', [
            'file' => $muxFile,
            'reason' => $reason,
        ]);
    }
}
