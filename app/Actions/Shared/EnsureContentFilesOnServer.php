<?php

namespace App\Actions\Shared;

use App\Models\LocalFileVolume;
use App\Models\Server;
use Closure;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * `docker compose up` creates a missing bind source as an empty directory. The queued
 * ServerStorageSaveJob writes new content files, but it can run after the containers start. This
 * action writes each missing content file before `docker compose up`.
 *
 * It checks all files with one server command. It writes a file only when the path is missing or
 * is an empty directory. It does not change a file that exists, and it does not delete a directory
 * that is not empty.
 */
class EnsureContentFilesOnServer
{
    use AsAction;

    /**
     * @param  iterable<LocalFileVolume>  $fileStorages
     * @param  Closure(string, string): void  $log  Gets a log line and its type (`stdout` or `stderr`). Log lines never contain file content.
     * @return int The number of files that Coolify wrote
     */
    public function handle(iterable $fileStorages, Server $server, Closure $log): int
    {
        $candidates = [];
        foreach ($fileStorages as $fileStorage) {
            if (! $fileStorage->hasContentToWrite()) {
                continue;
            }
            try {
                $candidates[] = [$fileStorage, $fileStorage->contentPathOnServer()];
            } catch (\Throwable $e) {
                $log('Warning: '.$this->plainText($e->getMessage()), 'stderr');
            }
        }

        if ($candidates === []) {
            return 0;
        }

        $states = LocalFileVolume::remoteFileStates(array_column($candidates, 1), $server);

        $toWrite = [];
        foreach ($candidates as $index => [$fileStorage, $path]) {
            match ($states[$index]) {
                'missing', 'empty-directory' => $toWrite[] = [$fileStorage, $path],
                'file' => null,
                'directory' => $log("Warning: A directory that is not empty is at {$path} on the server. Coolify did not write the configuration file for {$fileStorage->mount_path}. Remove the directory on the server, then start the resource again.", 'stderr'),
                'other' => $log("Warning: A symbolic link or a special file is at {$path} on the server. Coolify did not write the configuration file for {$fileStorage->mount_path}.", 'stderr'),
                default => $log("Warning: Coolify cannot check the configuration file {$path} on the server.", 'stderr'),
            };
        }

        if ($toWrite === []) {
            return 0;
        }

        $count = count($toWrite);
        $log($count === 1 ? 'Writing 1 missing configuration file.' : "Writing {$count} missing configuration files.", 'stdout');

        $written = 0;
        foreach ($toWrite as [$fileStorage, $path]) {
            try {
                $fileStorage->saveStorageOnServer();
                $written++;
            } catch (\Throwable $e) {
                $log("Warning: Coolify cannot write the configuration file {$path}: ".$this->plainText($e->getMessage()), 'stderr');
            }
        }

        return $written;
    }

    /**
     * Runs the action and returns its log lines as `echo` commands, for a command list that runs
     * later in an activity log.
     *
     * @param  iterable<LocalFileVolume>  $fileStorages
     * @return list<string>
     */
    public static function echoCommands(iterable $fileStorages, Server $server): array
    {
        $commands = [];
        static::run($fileStorages, $server, function (string $message) use (&$commands): void {
            $commands[] = 'echo '.escapeshellarg($message);
        });

        return $commands;
    }

    private function plainText(string $message): string
    {
        return trim(preg_replace('/\s+/', ' ', strip_tags(str_replace('<br>', ' ', $message))));
    }
}
