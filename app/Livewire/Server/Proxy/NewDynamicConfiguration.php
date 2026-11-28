<?php

namespace App\Livewire\Server\Proxy;

use App\Enums\ProxyTypes;
use App\Models\Server;
use App\Rules\ValidProxyConfigFilename;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Symfony\Component\Yaml\Yaml;

class NewDynamicConfiguration extends Component
{
    use AuthorizesRequests;

    public string $fileName = '';

    public string $value = '';

    public bool $newFile = true;

    public ?Server $server = null;

    #[Locked]
    public $server_id;

    #[Locked]
    public $parameters = [];

    public function mount()
    {
        $this->server = Server::ownedByCurrentTeam()->whereId($this->server_id)->first();
        if (is_null($this->server)) {
            $this->redirectRoute('server.index');

            return;
        }
        $this->parameters = get_route_parameters();
        if ($this->fileName !== '') {
            $this->fileName = str_replace('|', '.', $this->fileName);
        }
    }

    public function addDynamicConfiguration()
    {
        try {
            if (is_null($this->server)) {
                return redirect()->route('server.index');
            }
            $this->authorize('update', $this->server);
            $this->validate([
                'fileName' => ['required', new ValidProxyConfigFilename],
                'value' => 'required',
            ]);

            validateFilenameSafe($this->fileName, 'proxy configuration filename');

            $proxy_type = $this->server->proxyType();
            if ($proxy_type === ProxyTypes::TRAEFIK->value) {
                if (! str($this->fileName)->endsWith('.yaml') && ! str($this->fileName)->endsWith('.yml')) {
                    $this->fileName = "{$this->fileName}.yaml";
                }
                if ($this->fileName === 'coolify.yaml') {
                    $this->dispatch('error', 'File name is reserved.');

                    return;
                }
            } elseif ($proxy_type === 'CADDY') {
                if (! str($this->fileName)->endsWith('.caddy')) {
                    $this->fileName = "{$this->fileName}.caddy";
                }
            }
            $proxy_path = $this->server->proxyPath();
            $file = "{$proxy_path}/dynamic/{$this->fileName}";
            $escapedFile = escapeshellarg($file);

            if ($this->newFile) {
                $exists = instant_remote_process(["test -f {$escapedFile} && echo 1 || echo 0"], $this->server);
                if ($exists == 1) {
                    $this->dispatch('error', 'File already exists');

                    return;
                }
            }
            if ($proxy_type === ProxyTypes::TRAEFIK->value) {
                $yaml = Yaml::parse($this->value);
                $yaml = Yaml::dump($yaml, 10, 2);
                $this->value = $yaml;
            }
            $isCaddy = $proxy_type === 'CADDY';
            // Base64 keeps the exact bytes: the command output is trimmed.
            $previousValue = $isCaddy && ! $this->newFile ? base64_decode(instant_remote_process(["base64 -w0 {$escapedFile}"], $this->server)) : null;
            $this->writeFile($escapedFile, $this->value);
            if ($isCaddy) {
                $this->reloadCaddyOrRestore($escapedFile, $previousValue);
            }
            $this->dispatch('close-modal');
            $this->dispatch('loadDynamicConfigurations');
            $this->dispatch('dynamic-configuration-added');
            $this->dispatch('success', 'Dynamic configuration saved.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    /**
     * A global options block has no key. Caddy accepts it only as the first block of the Caddyfile.
     */
    private static function startsWithCaddyGlobalOptions(string $caddyfile): bool
    {
        foreach (preg_split('/\R/', $caddyfile) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            // "{$DOMAIN} {" is a site address from an environment variable, not a global block.
            return preg_match('/^\{(\s|$)/', $line) === 1;
        }

        return false;
    }

    /**
     * Caddy keeps its running configuration when a reload fails, but it would fail to load the
     * saved file on the next proxy start. So a file that Caddy rejects is not kept.
     */
    private function reloadCaddyOrRestore(string $escapedFile, ?string $previousValue): void
    {
        // A stopped proxy reads the file when it starts.
        if (getContainerStatus($this->server, 'coolify-proxy') !== 'running') {
            return;
        }
        try {
            $this->server->reloadCaddy();
        } catch (\Throwable $e) {
            if ($previousValue === null) {
                instant_remote_process(["rm -f {$escapedFile}"], $this->server);
            } else {
                $this->writeFile($escapedFile, $previousValue);
            }

            // Global options are valid only where Caddy reads them first (for example a custom base file).
            $hint = self::startsWithCaddyGlobalOptions($this->value)
                ? ' Caddy reads global options only from the first block of the Caddyfile. Add them as labels of the proxy container on the Configuration page, for example caddy.email=you@example.com.'
                : '';

            throw new \RuntimeException('Caddy rejected the configuration, so it was not saved. '.$e->getMessage().$hint, previous: $e);
        }
    }

    private function writeFile(string $escapedFile, string $value): void
    {
        $base64_value = base64_encode($value);
        instant_remote_process([
            "echo '{$base64_value}' | base64 -d | tee {$escapedFile} > /dev/null",
        ], $this->server);
    }

    public function render()
    {
        return view('livewire.server.proxy.new-dynamic-configuration');
    }
}
