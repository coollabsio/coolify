<?php

use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Storage;

class LocalStorage extends Facade
{
    public static function deployments()
    {
        $storage = Storage::build([
            'driver' => 'local',
            'root' => storage_path("app/private/deployments"),
            'visibility' => 'private',
        ]);
        $storage->makeDirectory('.');
        return $storage;
    }
    public static function ssh_keys()
    {
        $storage = Storage::build([
            'driver' => 'local',
            'root' => storage_path("app/private/ssh/keys"),
            'visibility' => 'private'
        ]);
        $storage->makeDirectory('.');
        return $storage;
    }
    public static function ssh_mux()
    {
        $storage = Storage::build([
            'driver' => 'local',
            'root' => storage_path("app/private/ssh/mux"),
            'visibility' => 'private',
        ]);
        $storage->makeDirectory('.');
        return $storage;
    }
}
