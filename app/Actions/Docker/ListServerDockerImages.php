<?php

namespace App\Actions\Docker;

use Illuminate\Database\Eloquent\Collection;
use Lorisleiva\Actions\Concerns\AsAction;

class ListServerDockerImages
{
    use AsAction;

    public Collection $server;

    public static function run($server)
    {

        $jsonString = instant_remote_process(["docker images --format '{{json .}}'"], $server);

        return collect(explode("\n", trim($jsonString)))
            ->filter()
            ->map(function ($line) {
                $data = json_decode($line, true);
                if (!$data) return null;

                return [
                    'tag' => $data['Tag'] ?? '<none>',
                    'id' => $data['ID'] ?? '',
                    'created_at' => $data['CreatedAt'] ?? '',
                    'size' => $data['Size'] ?? '',
                    'name' => $data['Repository'] ?? '',
                ];
            })
            ->filter()
            ->values()
            ->toArray();
    }
}
