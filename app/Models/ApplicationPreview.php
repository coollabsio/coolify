<?php

namespace App\Models;

class ApplicationPreview extends BaseModel
{
    protected $guarded = [];
    protected static function booted()
    {
        static::deleting(function ($preview) {
            if ($preview->application->build_pack === 'dockercompose') {
                $server = $preview->application->destination->server;
                $composeFile = $preview->application->parseCompose(pull_request_id: $preview->pull_request_id);
                $volumes = data_get($composeFile, 'volumes');
                $networks = data_get($composeFile, 'networks');
                $networkKeys = collect($networks)->keys();
                $volumeKeys = collect($volumes)->keys();
                $volumeKeys->each(function ($key) use ($server) {
                    instant_remote_process(["docker volume rm -f $key"], $server, false);
                });
                $networkKeys->each(function ($key) use ($server) {
                    instant_remote_process(["docker network disconnect $key coolify-proxy"], $server, false);
                    instant_remote_process(["docker network rm $key"], $server, false);
                });
            }
        });
    }
    static function findPreviewByApplicationAndPullId(int $application_id, int $pull_request_id)
    {
        return self::where('application_id', $application_id)->where('pull_request_id', $pull_request_id)->firstOrFail();
    }

    public function application()
    {
        return $this->belongsTo(Application::class);
    }
}
