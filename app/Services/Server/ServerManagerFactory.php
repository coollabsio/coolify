<?php

namespace App\Services\Server;

use App\Models\Server;
use Illuminate\Filesystem\FilesystemManager;

class ServerManagerFactory
{
    private FilesystemManager $storage;

    public function __construct(FilesystemManager $storage)
    {
        $this->storage = $storage;
    }

    public function forServer(Server $server): ServerManager
    {
        return new ServerManager($server, $this->storage);
    }
}
