<?php

namespace App\Services;

use App\Helpers\SshMultiplexingHelper;
use App\Helpers\SshRetryHandler;
use App\Models\S3Storage;
use App\Models\Server;
use Throwable;

/**
 * Copies backup files between a server and an SFTP, FTPS, or WebDAV storage with rclone, in a container on the
 * server. S3 storages use the MinIO client in the helper image instead.
 */
class RcloneTransfer
{
    private const CONFIG_PATH = '/tmp/rclone.conf';

    /**
     * Docker label of the container. CleanupHelperContainersJob removes labeled containers of jobs that ended.
     */
    public const LABEL = 'coolify.type=rclone';

    /**
     * Lifetime of a container started without a timeout, and the time after the timeout that a container stays.
     */
    private const DEFAULT_LIFETIME_SECONDS = 86400;

    private const LIFETIME_MARGIN_SECONDS = 300;

    public function __construct(
        private S3Storage $storage,
        private Server $server,
        private string $container,
    ) {}

    /**
     * Starts the rclone container and writes the storage config to it.
     *
     * @param  array<int, string>  $volumes  `docker run -v` values, for example "/host/path:/path:ro".
     * @param  ?int  $timeout  Timeout of the job. The container stops itself shortly after it, also when a worker
     *                         timeout kills the job before it removes the container with the config.
     */
    public function start(array $volumes = [], ?string $network = null, ?int $timeout = null): void
    {
        $options = collect($volumes)->map(fn (string $volume): string => ' -v '.escapeshellarg($volume))->implode('');
        if ($network !== null) {
            $options .= ' --network '.escapeshellarg($network);
        }

        $lifetime = $timeout === null ? self::DEFAULT_LIFETIME_SECONDS : $timeout + self::LIFETIME_MARGIN_SECONDS;

        // Remove a leftover container in the same batch, so a replayed batch does not fail with a name conflict.
        instant_remote_process([
            $this->removeCommand(),
            'docker run -d --rm --name '.escapeshellarg($this->container).' --label '.self::LABEL.$options
                .' --entrypoint sleep '.escapeshellarg(self::image()).' '.$lifetime.' >/dev/null',
        ], $this->server, true, false, $timeout, disableMultiplexing: true);

        $this->writeConfig();
    }

    /**
     * The default image follows the registry setting of the instance, as coolifyHelperImage() does. Docker Hub and
     * GitHub Container Registry publish the same rclone/rclone digest. An RCLONE_IMAGE from another registry is used
     * as given.
     */
    public static function image(): string
    {
        $image = (string) config('constants.coolify.rclone_image');
        $defaultPrefix = config('constants.coolify.registry_url', 'docker.io').'/rclone/rclone:';

        return str_starts_with($image, $defaultPrefix)
            ? coolifyRegistryUrl().'/rclone/rclone:'.substr($image, strlen($defaultPrefix))
            : $image;
    }

    /**
     * Uploads a local file. rclone writes "<name>.<8 hex digits>.partial" and renames it at the end, so after a failed
     * upload this deletes the partial files of this file, and no other file.
     */
    public function upload(string $localPath, string $remoteFile, ?int $timeout = null): void
    {
        try {
            instant_remote_process([$this->uploadCommand($localPath, $remoteFile)], $this->server, true, false, $timeout, disableMultiplexing: true);
        } catch (Throwable $exception) {
            $remotePath = $this->storage->rcloneRemotePath($remoteFile);
            $separator = strrpos($remotePath, '/');
            $directory = $separator === false ? S3Storage::RCLONE_REMOTE.':' : substr($remotePath, 0, $separator);
            $name = $separator === false ? substr($remotePath, strlen(S3Storage::RCLONE_REMOTE) + 1) : substr($remotePath, $separator + 1);

            instant_remote_process([
                // An upload that timed out can still run in the container; stop it before its partial file is deleted.
                'docker exec '.escapeshellarg($this->container).' pkill -x rclone >/dev/null 2>&1 || true',
                $this->rcloneCommand('delete', $directory, '--max-depth', '1', '--include', '/'.addcslashes($name, '\\*?[]{}').'.'.str_repeat('[0-9a-f]', 8).'.partial'),
            ], $this->server, throwError: false, disableMultiplexing: true);

            throw $exception;
        }
    }

    private function uploadCommand(string $localPath, string $remoteFile): string
    {
        return $this->rcloneCommand('copyto', $localPath, $this->storage->rcloneRemotePath($remoteFile));
    }

    public function downloadCommand(string $remoteFile, string $localPath): string
    {
        return $this->rcloneCommand('copyto', $this->storage->rcloneRemotePath($remoteFile), $localPath);
    }

    public function removeCommand(): string
    {
        return 'docker rm -f '.escapeshellarg($this->container).' >/dev/null 2>&1 || true';
    }

    private function rcloneCommand(string ...$arguments): string
    {
        return 'docker exec '.escapeshellarg($this->container).' rclone --config '.self::CONFIG_PATH.' --contimeout 15s'
            .collect($arguments)->map(fn (string $argument): string => ' '.escapeshellarg($argument))->implode('');
    }

    /**
     * The config goes to the container through the stdin of the SSH process, so the password is not in the arguments
     * of any process on Coolify or on the server.
     */
    private function writeConfig(): void
    {
        $remoteCommand = ($this->server->isNonRoot() ? 'sudo ' : '').'docker exec -i '.escapeshellarg($this->container)
            .' sh -c '.escapeshellarg('umask 077 && cat > '.self::CONFIG_PATH);
        $timeout = (int) config('constants.ssh.command_timeout');
        $config = $this->storage->rcloneConfig();

        SshRetryHandler::retry(
            function () use ($remoteCommand, $timeout, $config) {
                $sshCommand = SshMultiplexingHelper::generateSshStdinCommand($this->server, $remoteCommand, disableMultiplexing: true, commandTimeout: $timeout);
                $process = runSshProcess($sshCommand, $timeout, $config);

                if ($process->exitCode() !== 0) {
                    excludeCertainErrors($process->errorOutput(), $process->exitCode());
                }
            },
            ['server' => $this->server->ip, 'function' => 'writeRcloneConfig'],
        );
    }
}
