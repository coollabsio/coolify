<?php

namespace App\Models;

use App\Events\FileStorageChanged;
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
        $commands = collect([]);
        $fs_path = data_get($this, 'fs_path');
        $isFile = instant_remote_process(["test -f $fs_path && echo OK || echo NOK"], $server);
        $isDir = instant_remote_process(["test -d $fs_path && echo OK || echo NOK"], $server);
        if ($fs_path && $fs_path != '/' && $fs_path != '.' && $fs_path != '..') {
            ray($isFile, $isDir);
            if ($isFile === 'OK') {
                $commands->push("rm -rf $fs_path > /dev/null 2>&1 || true");

            } elseif ($isDir === 'OK') {
                $commands->push("rm -rf $fs_path > /dev/null 2>&1 || true");
                $commands->push("rmdir $fs_path > /dev/null 2>&1 || true");
            }
        }
        if ($commands->count() > 0) {
            return instant_remote_process($commands, $server);
        }
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
        $commands = collect([]);
        if ($this->is_directory) {
            $commands->push("mkdir -p $this->fs_path > /dev/null 2>&1 || true");
            $commands->push("cd $workdir");
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
            $content = instant_remote_process(["cat $path"], $server, false);
            $fileVolume->is_directory = false;
            $fileVolume->content = $content;
            $fileVolume->save();
            FileStorageChanged::dispatch(data_get($server, 'team_id'));
            throw new \Exception('The following file is a file on the server, but you are trying to mark it as a directory. Please delete the file on the server or mark it as directory.');
        } elseif ($isDir == 'OK' && ! $fileVolume->is_directory) {
            $fileVolume->is_directory = true;
            $fileVolume->save();
            throw new \Exception('The following file is a directory on the server, but you are trying to mark it as a file. <br><br>Please delete the directory on the server or mark it as directory.');
        }
        if ($isDir == 'NOK' && ! $fileVolume->is_directory) {
            $chmod = data_get($fileVolume, 'chmod');
            $chown = data_get($fileVolume, 'chown');
            if ($content) {
                $content = base64_encode($content);
                $commands->push("echo '$content' | base64 -d | tee $path > /dev/null");
            } else {
                $commands->push("touch $path");
            }
            $commands->push("chmod +x $path");
            if ($chown) {
                $commands->push("chown $chown $path");
            }
            if ($chmod) {
                $commands->push("chmod $chmod $path");
            }
        } elseif ($isDir == 'NOK' && $fileVolume->is_directory) {
            $commands->push("mkdir -p $path > /dev/null 2>&1 || true");
        }

        return instant_remote_process($commands, $server);
    }
}
