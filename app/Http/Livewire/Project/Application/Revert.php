<?php

namespace App\Http\Livewire\Project\Application;

use App\Models\Application;
use Livewire\Component;
use Illuminate\Support\Str;

class Revert extends Component
{
    public Application $application;
    public $images = [];
    public string $current;
    public function loadImages()
    {
        try {
            $image = $this->application->uuid;
            $output = instantRemoteProcess([
                "docker inspect --format='{{.Config.Image}}' {$this->application->uuid}",
            ], $this->application->destination->server, throwError: false);
            $current_tag = Str::of($output)->trim()->explode(":");
            $this->current = data_get($current_tag, 1);

            $output = instantRemoteProcess([
                "docker images --format '{{.Repository}}#{{.Tag}}#{{.CreatedAt}}'",
            ], $this->application->destination->server);
            $this->images = Str::of($output)->trim()->explode("\n")->filter(function ($item) use ($image) {
                return Str::of($item)->contains($image);
            })->map(function ($item) {
                $item = Str::of($item)->explode('#');
                if ($item[1] === $this->current) {
                    $item[1] = $item[1] . " (current)";
                }
                return [
                    'tag' => $item[1],
                    'createdAt' => $item[2],
                ];
            })->toArray();
        } catch (\Throwable $e) {
            return generalErrorHandler($e, $this);
        }
    }
}
