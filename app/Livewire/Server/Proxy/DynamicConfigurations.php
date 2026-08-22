<?php

namespace App\Livewire\Server\Proxy;

use App\Models\Server;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Livewire\Component;

class DynamicConfigurations extends Component
{
    use AuthorizesRequests;

    public const MAX_CONFIGURATION_FILE_SIZE_BYTES = 1024 * 1024;

    public const MAX_TOTAL_CONFIGURATION_SIZE_BYTES = 5 * 1024 * 1024;

    public const MAX_CONFIGURATION_FILES = 100;

    public ?Server $server = null;

    public $parameters = [];

    public Collection $contents;

    public function getListeners()
    {
        $teamId = auth()->user()->currentTeam()->id;

        return [
            "echo-private:team.{$teamId},ProxyStatusChangedUI" => 'loadDynamicConfigurations',
            'loadDynamicConfigurations',
        ];
    }

    protected $rules = [
        'contents.*' => 'nullable|string',
    ];

    public function initLoadDynamicConfigurations()
    {
        $this->loadDynamicConfigurations();
    }

    public function loadDynamicConfigurations()
    {
        try {
            $this->authorize('view', $this->server);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
        $proxy_path = $this->server->proxyPath();
        $fileLimit = self::MAX_CONFIGURATION_FILES + 1;
        $files = instant_remote_process(["mkdir -p $proxy_path/dynamic && ls -1 {$proxy_path}/dynamic | head -n {$fileLimit}"], $this->server);
        $files = collect(explode("\n", $files))->filter(fn ($file) => ! empty($file));
        $files = $files->map(fn ($file) => trim($file));
        $files = $files->sort();
        $contents = collect([]);
        $skippedFiles = collect([]);
        $totalBytes = 0;
        if ($files->count() > self::MAX_CONFIGURATION_FILES) {
            $skippedFiles->push('additional files');
        }
        foreach ($files->take(self::MAX_CONFIGURATION_FILES) as $file) {
            $without_extension = str_replace('.', '|', $file);
            $filePath = escapeshellarg("{$proxy_path}/dynamic/{$file}");
            $readLimit = self::MAX_CONFIGURATION_FILE_SIZE_BYTES + 1;
            $content = instant_remote_process(["head -c {$readLimit} {$filePath}"], $this->server);
            $content = $content ?? '';
            $contentBytes = strlen($content);

            if ($contentBytes > self::MAX_CONFIGURATION_FILE_SIZE_BYTES || $totalBytes + $contentBytes > self::MAX_TOTAL_CONFIGURATION_SIZE_BYTES) {
                $skippedFiles->push($file);

                continue;
            }

            $contents[$without_extension] = $content;
            $totalBytes += $contentBytes;
        }
        if ($skippedFiles->isNotEmpty()) {
            $this->dispatch('warning', 'Some dynamic configurations were not loaded because they exceed the safe display limits: '.$skippedFiles->implode(', '));
        }
        $this->contents = $contents;
        $this->dispatch('$refresh');
        $this->dispatch('success', 'Dynamic configurations loaded.');
    }

    public function mount()
    {
        $this->parameters = get_route_parameters();
        try {
            $this->server = Server::ownedByCurrentTeam()->whereUuid(request()->server_uuid)->first();
            if (is_null($this->server)) {
                return redirect()->route('server.index');
            }
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.server.proxy.dynamic-configurations');
    }
}
