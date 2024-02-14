<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;

class LocalFileVolume extends BaseModel
{
    use HasFactory;
    protected $guarded = [];

    public function service()
    {
        return $this->morphTo('resource');
    }
    public function saveStorageOnServer(ServiceApplication|ServiceDatabase $service)
    {
        $workdir = $service->service->workdir();
        $server = $service->service->server;
        $commands = collect([
            "mkdir -p $workdir > /dev/null 2>&1 || true",
            "cd $workdir"
        ]);
        $fileVolume = $this;
        $path = Str::of(data_get($fileVolume, 'fs_path'));
        $content = data_get($fileVolume, 'content');
        if ($path->startsWith('.')) {
            $path = $path->after('.');
            $path = $workdir . $path;
        }
        $isFile = instant_remote_process(["test -f $path && echo OK || echo NOK"], $server);
        $isDir = instant_remote_process(["test -d $path && echo OK || echo NOK"], $server);
        if ($isFile == 'OK' && $fileVolume->is_directory) {
            throw new \Exception("File $path is a file on the server, but you are trying to mark it as a directory. Please delete the file on the server or mark it as directory.");
        } else if ($isDir == 'OK' && !$fileVolume->is_directory) {
            throw new \Exception("File $path is a directory on the server, but you are trying to mark it as a file. Please delete the directory on the server or mark it as directory.");
        }
        if (!$fileVolume->is_directory && $isDir == 'NOK') {
            $content = base64_encode($content);
            $commands->push("echo '$content' | base64 -d > $path");
        } else if ($isDir == 'NOK' && $fileVolume->is_directory) {
            $commands->push("mkdir -p $path > /dev/null 2>&1 || true");
        }
        ray($commands->toArray());
        return instant_remote_process($commands, $server);
    }
}
