<?php

namespace App\Services\Server;

use App\Models\Server;
use Exception;
use Illuminate\Filesystem\FilesystemManager;

class ServerManager
{
    private Server $server;

    private FilesystemManager $filesystemManager;

    public function __construct(Server $server, FilesystemManager $filesystemManager)
    {
        $this->server = $server;
        $this->filesystemManager = $filesystemManager;
    }

    /**
     * @throws Exception
     *
     * @see savePrivateKeyToFs
     */
    public function savePrivateKeyToFileSystem(): string
    {
        if ($this->server->privateKey === null) {
            throw new Exception("Server {$this->server->name} does not have a private key");
        }

        ['location' => $location, 'private_key_filename' => $private_key_filename] = $this->getSshConfiguration();

        $this->filesystemManager->disk('ssh-keys')->makeDirectory('.');
        $this->filesystemManager->disk('ssh-mux')->makeDirectory('.');
        $this->filesystemManager->disk('ssh-keys')
            ->put($private_key_filename, $this->server->privateKey->private_key);

        return $location;

    }

    /**
     * @throws Exception
     */
    public function getSshConfiguration()
    {
        $uuid = $this->server->uuid;
        if (is_null($uuid)) {
            throw new Exception('Server does not have a uuid');
        }

        $private_key_filename = "id.root@{$this->server->uuid}";
        $location = '/var/www/html/storage/app/ssh/keys/'.$private_key_filename;
        $mux_filename = '/var/www/html/storage/app/ssh/mux/'.$this->server->muxFilename();

        return [
            'location' => $location,
            'mux_filename' => $mux_filename,
            'private_key_filename' => $private_key_filename,
        ];
    }
}
