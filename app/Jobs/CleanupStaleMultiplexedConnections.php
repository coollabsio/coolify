<?php

namespace App\Jobs;

use App\Helpers\SshMultiplexingHelper;
use App\Models\Server;
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
     * and they hold an authenticated session until the container restarts.
     *
     * Every `ssh -fN` for a server carries the same ControlPath, so the socket
     * file existing says nothing about which process owns it. Ownership is read
     * from the socket itself (`ssh -O check` reports the master pid); every
     * other process for that ControlPath is an orphan. When the socket file is
     * missing, no process owns it. When it exists but does not answer, the
     * owner is unknown and nothing is reaped.
     *
     * Processes younger than the minimum age are skipped: a freshly forked
     * master creates its socket a few milliseconds after starting, so a young
     * process with no socket may simply be mid-establish rather than orphaned.
     */
    private function cleanupOrphanedSshProcesses(): void
    {
        $muxDir = storage_path('app/ssh/mux');
        $minAge = (int) config('constants.ssh.mux_orphan_min_age');
        $socketOwners = [];

        foreach ($this->listProcesses() as $process) {
            // Backgrounded ssh master: current `ssh -fN` or legacy `ssh -fNM`.
            if (! preg_match('#(^|/)ssh -fN#', $process['args'])) {
                continue;
            }

            // Only ever touch ssh processes pointing at Coolify's mux directory.
            if (! preg_match('#ControlPath=('.preg_quote($muxDir, '#').'/\S+)#', $process['args'], $pathMatch)) {
                continue;
            }

            $muxSocket = $pathMatch[1];
            if ($process['etimes'] < $minAge
                || SshMultiplexingHelper::isMuxProcessRetiring($process['pid'], $muxSocket)) {
                continue;
            }

            $socketOwners[$muxSocket] ??= $this->muxSocketOwner($muxSocket);
            if ($socketOwners[$muxSocket] !== false && $socketOwners[$muxSocket] !== $process['pid']) {
                $this->reapOrphan('ssh', $process);
            }
        }
    }

    /**
     * Pid of the master that owns a mux socket. Returns null when the socket
     * file does not exist, and false when it exists but its owner is unknown.
     */
    private function muxSocketOwner(string $muxSocket): string|false|null
    {
        if (! file_exists($muxSocket)) {
            return null;
        }

        $check = SshMultiplexingHelper::runMuxSocketCommand($muxSocket, 'check');

        return $check && preg_match('/pid=(\d+)/', $check->output().$check->errorOutput(), $matches)
            ? $matches[1]
            : false;
    }

    /**
     * Kill orphaned `cloudflared access ssh` proxy processes. Each is spawned
     * as the SSH ProxyCommand transport for a Cloudflare Tunnel server and must
     * die with its parent ssh. When that ssh is killed or orphaned (e.g. a lost
     * mux master), the cloudflared process can leak and accumulate. A legitimate
     * proxy always has a live ssh at the other end of its stdin pipe. Its parent
     * is not enough: `ssh -f` forks after connecting, so the proxy of a live
     * master is reparented away from ssh.
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
            // `cloudflared access ssh`, never the `cloudflared tunnel` daemon, nor
            // an ssh whose ProxyCommand option contains the same words.
            if (isset($sshPids[$process['pid']]) || ! str_contains($process['args'], 'cloudflared access ssh')) {
                continue;
            }

            if ($process['etimes'] >= $minAge
                && ! isset($sshPids[$process['ppid']])
                && ! $this->stdinPipeHeldByAny($process['pid'], array_keys($sshPids))) {
                $this->reapOrphan('cloudflared', $process);
            }
        }
    }

    /**
     * Whether one of the given processes holds the other end of a process's
     * stdin pipe. An unreadable stdin counts as held, so it is never reaped.
     *
     * @param  list<int|string>  $holderPids
     */
    private function stdinPipeHeldByAny(string $pid, array $holderPids): bool
    {
        $stdin = @readlink("/proc/{$pid}/fd/0");
        if ($stdin === false || ! str_starts_with($stdin, 'pipe:')) {
            return true;
        }

        foreach ($holderPids as $holderPid) {
            foreach (glob("/proc/{$holderPid}/fd/*") ?: [] as $fd) {
                if (@readlink($fd) === $stdin) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Reap a detected orphan process. When orphan reaping is disabled
     * (SSH_MUX_ORPHAN_REAP_ENABLED=false, the default), the orphan is only
     * logged — a dry-run mode that lets operators verify what would be killed
     * before enabling it for real.
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

        // SIGKILL ssh: a former master unlinks its ControlPath on SIGTERM, even
        // when a newer master owns that path now.
        $signal = $kind === 'ssh' ? 'kill -9 ' : 'kill ';
        Process::run($signal.escapeshellarg($process['pid']));
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
            if (! SshMultiplexingHelper::runMuxSocketCommand($muxSocket, 'check')?->successful()) {
                $this->removeMultiplexFile($muxFile, 'connection_check_failed');
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
     * (SSH_MUX_ORPHAN_REAP_ENABLED=false, the default), the file is only logged —
     * a dry-run mode that lets operators verify what would be removed before
     * enabling it for real.
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
        $checkProcess = SshMultiplexingHelper::runMuxSocketCommand($muxSocket, 'check');
        $pid = $checkProcess && preg_match('/pid=(\d+)/', $checkProcess->output().$checkProcess->errorOutput(), $matches)
            ? $matches[1]
            : null;

        if ($pid !== null) {
            SshMultiplexingHelper::markMuxProcessAsRetiring($pid, $muxSocket);
        }

        $stopProcess = SshMultiplexingHelper::runMuxSocketCommand($muxSocket, 'stop');
        if ($pid !== null && ! $stopProcess?->successful()) {
            SshMultiplexingHelper::unmarkMuxProcessAsRetiring($pid, $muxSocket);
        }
        Storage::disk('ssh-mux')->delete($muxFile);

        Log::info('Removed stale mux file', [
            'file' => $muxFile,
            'reason' => $reason,
        ]);
    }
}
