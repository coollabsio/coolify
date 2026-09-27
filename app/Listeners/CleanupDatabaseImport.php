<?php

namespace App\Listeners;

use App\Events\DatabaseImportFinished;
use App\Models\Server;
use App\Support\DatabaseImport\DatabaseImportCleanup;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

class CleanupDatabaseImport implements ShouldQueue
{
    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [5, 15, 30];

    /**
     * Rounds (one second each) of the restore stop script, and how many of them send SIGTERM before SIGKILL.
     */
    private const STOP_ROUNDS = 30;

    private const STOP_TERM_ROUNDS = 10;

    /**
     * Placeholders: __OPERATION_PATH__ (quoted), __ROUNDS__, __TERM_ROUNDS__.
     * The script has no command substitution, so the non-root sudo parser does not change it.
     */
    private const STOP_RESTORE_SCRIPT = <<<'SH'
op=__OPERATION_PATH__
info() {
  pp=; st=
  while read -r k v rest; do
    case "$k" in PPid:) pp=$v ;; State:) st=$v ;; esac
  done 2>/dev/null < "/proc/$1/status"
}
skip=" $$ "
p=$$
while [ "$p" -gt 1 ] 2>/dev/null; do
  info "$p"
  [ -n "$pp" ] || break
  skip="$skip$pp "
  p=$pp
done
scan() {
  all=; zombies=; pids=
  for d in /proc/[0-9]*; do
    p=${d#/proc/}
    case "$skip" in *" $p "*) continue ;; esac
    info "$p"
    [ -n "$pp" ] || continue
    all="$all $p:$pp"
    if [ "$st" = Z ]; then zombies="$zombies $p"; continue; fi
    if tr '\000' ' ' < "$d/cmdline" 2>/dev/null | grep -qF -- "$op"; then pids="$pids $p"; fi
  done
  found=1
  while [ -n "$found" ]; do
    found=
    for e in $all; do
      p=${e%%:*}
      case "$pids " in *" $p "*) continue ;; esac
      case "$pids " in *" ${e#*:} "*) pids="$pids $p"; found=1 ;; esac
    done
  done
}
roots=
for round in __ROUNDS__; do
  scan
  [ -n "$pids" ] || exit 0
  sig=TERM
  [ "$round" -le __TERM_ROUNDS__ ] || sig=KILL
  previous=$roots; roots=; targets=
  for e in $all; do
    p=${e%%:*}; pp=${e#*:}
    case "$pids " in *" $p "*) ;; *) continue ;; esac
    case "$zombies " in *" $p "*) continue ;; esac
    case "$all " in *":$p "*) continue ;; esac
    [ "$pp" != 1 ] || continue
    case "$pids " in
      *" $pp "*) targets="$targets $p" ;;
      *) roots="$roots $p"; case "$previous " in *" $p "*) targets="$targets $p" ;; esac ;;
    esac
  done
  if [ -n "$targets" ]; then
    echo "Stopping the database import processes ($sig):$targets"
    kill -$sig $targets 2>/dev/null
  fi
  sleep 1
done
echo "Some database import processes still run:$pids" >&2
exit 0
SH;

    public function handle(DatabaseImportFinished $event): void
    {
        $operation = $event->data['operationUuid'] ?? null;
        $claimed = is_string($operation) && Str::isUuid($operation);

        // The normal finish and a stop after a restart or a stale import can both send the event.
        if ($claimed && ! DatabaseImportCleanup::claim($operation)) {
            return;
        }

        try {
            $commands = $this->commands($event->data);
            $server = Server::query()->find($event->data['serverId'] ?? null);

            if ($server && $commands !== []) {
                instant_remote_process($commands, $server);
            }
        } catch (Throwable $exception) {
            if ($claimed) {
                DatabaseImportCleanup::release($operation);
            }

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    public function commands(array $data): array
    {
        $commands = [];

        if (($data['stopRestore'] ?? false) === true && filled($data['container'] ?? null) && Str::isUuid($data['operationUuid'] ?? null)) {
            $commands[] = $this->stopRestoreCommand($data['container'], $data['operationUuid']);
        }

        if (filled($data['containerName'] ?? null)) {
            $commands[] = 'docker rm -f '.escapeshellarg($data['containerName']).' 2>/dev/null || true';
        }

        if (isSafeTmpPath($data['serverTmpPath'] ?? null)) {
            $commands[] = 'rm -f '.escapeshellarg($data['serverTmpPath']).' 2>/dev/null || true';
        }

        if (isSafeTmpPath($data['credentialTmpPath'] ?? null)) {
            $commands[] = 'rm -f '.escapeshellarg($data['credentialTmpPath']).' 2>/dev/null || true';
        }

        if (filled($data['container'] ?? null)) {
            foreach (['containerTmpPath', 'scriptPath'] as $key) {
                if (isSafeTmpPath($data[$key] ?? null)) {
                    $commands[] = 'docker exec '.escapeshellarg($data['container']).' rm -f '.escapeshellarg($data[$key]).' 2>/dev/null || true';
                }
            }
        }

        return $commands;
    }

    /**
     * Stops a restore that still runs in the database container after its SSH session ended.
     *
     * `docker exec` processes are not stopped when the SSH session ends. The script selects the
     * processes with the unique operation path (/tmp/restore_{uuid}) in their command line and
     * all their child processes (for example psql or pg_restore). It never selects itself or
     * its parent processes.
     *
     * Processes are stopped from the bottom up. A process gets a signal only when it has no
     * child processes (zombies count until they are reaped), so its live parent reaps it.
     * A process whose parent is PID 1 never gets a signal: PostgreSQL runs as PID 1 and restarts
     * all connections when an unknown child process ends because of a signal. A top-level
     * process (its parent is outside the selection) gets a signal only after it had no child
     * processes for one full round, because a shell between two commands can fork at any time.
     * The first rounds send SIGTERM, later rounds SIGKILL.
     *
     * It uses only sh built-ins, tr, grep and sleep, because database images can lack pkill/pgrep.
     */
    public function stopRestoreCommand(string $container, string $operationUuid): string
    {
        if (! Str::isUuid($operationUuid)) {
            throw new InvalidArgumentException('The database import operation id is invalid.');
        }

        $script = strtr(self::STOP_RESTORE_SCRIPT, [
            '__OPERATION_PATH__' => escapeshellarg("/tmp/restore_{$operationUuid}"),
            '__ROUNDS__' => implode(' ', range(1, self::STOP_ROUNDS)),
            '__TERM_ROUNDS__' => (string) self::STOP_TERM_ROUNDS,
        ]);

        return 'docker exec '.escapeshellarg($container).' sh -c '.escapeshellarg($script).' 2>/dev/null || true';
    }

    public function failed(DatabaseImportFinished $event, Throwable $exception): void
    {
        Log::error('Database import cleanup failed', [
            'serverId' => $event->data['serverId'] ?? null,
            'containerName' => $event->data['containerName'] ?? null,
            'error' => $exception->getMessage(),
        ]);
    }
}
