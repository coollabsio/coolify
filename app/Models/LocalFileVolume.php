<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

class LocalFileVolume extends BaseModel
{
    use HasFactory;

    protected $guarded = [];

    protected static function booted()
    {
        static::created(function (LocalFileVolume $fileVolume) {
            $fileVolume->load(['service']);
            dispatch(new \App\Jobs\ServerStorageSaveJob($fileVolume));
        });
    }

    public function service()
    {
        return $this->morphTo('resource');
    }

    public function deleteStorageOnServer()
    {
        $isService = data_get($this->resource, 'service');
        if ($isService) {
            $workdir = $this->resource->service->workdir();
            $server = $this->resource->service->server;
        } else {
            $workdir = $this->resource->workdir();
            $server = $this->resource->destination->server;
        }
        $commands = collect([
            "cd $workdir",
        ]);
        $fs_path = data_get($this, 'fs_path');
        if ($fs_path && $fs_path != '/' && $fs_path != '.' && $fs_path != '..') {
            $commands->push("rm -rf $fs_path");
        }
        ray($commands);

        return instant_remote_process($commands, $server);
    }

    public function saveStorageOnServer()
    {
        $isService = data_get($this->resource, 'service');
        if ($isService) {
            $workdir = $this->resource->service->workdir();
            $server = $this->resource->service->server;
        } else {
            $workdir = $this->resource->workdir();
            $server = $this->resource->destination->server;
        }
        $commands = collect([
            "mkdir -p $workdir > /dev/null 2>&1 || true",
            "cd $workdir",
        ]);
        $is_directory = $this->is_directory;
        if ($is_directory) {
            $commands->push("mkdir -p $this->fs_path > /dev/null 2>&1 || true");
        }
        if (str($this->fs_path)->startsWith('.') || str($this->fs_path)->startsWith('/') || str($this->fs_path)->startsWith('~')) {
            $parent_dir = str($this->fs_path)->beforeLast('/');
            if ($parent_dir != '') {
                $commands->push("mkdir -p $parent_dir > /dev/null 2>&1 || true");
            }
        }
        $fileVolume = $this;
        $path = str(data_get($fileVolume, 'fs_path'));
        $content = data_get($fileVolume, 'content');
        if ($path->startsWith('.')) {
            $path = $path->after('.');
            $path = $workdir.$path;
        }
        $isFile = instant_remote_process(["test -f $path && echo OK || echo NOK"], $server);
        $isDir = instant_remote_process(["test -d $path && echo OK || echo NOK"], $server);
        if ($isFile == 'OK' && $fileVolume->is_directory) {
            throw new \Exception('The following file is a file on the server, but you are trying to mark it as a directory. Please delete the file on the server or mark it as directory.');
        } elseif ($isDir == 'OK' && ! $fileVolume->is_directory) {
            throw new \Exception('The following file is a directory on the server, but you are trying to mark it as a file. <br><br>Please delete the directory on the server or mark it as directory.');
        }
        if (! $fileVolume->is_directory && $isDir == 'NOK') {
            if ($content) {
                $content = base64_encode($content);
                $chmod = $fileVolume->chmod;
                $chown = $fileVolume->chown;
                $commands->push("echo '$content' | base64 -d | tee $path > /dev/null");
                $commands->push("chmod +x $path");
                if ($chown) {
                    $commands->push("chown $chown $path");
                }
                if ($chmod) {
                    $commands->push("chmod $chmod $path");
                }
            }
        } elseif ($isDir == 'NOK' && $fileVolume->is_directory) {
            $commands->push("mkdir -p $path > /dev/null 2>&1 || true");
        }

        return instant_remote_process($commands, $server);
    }
}
