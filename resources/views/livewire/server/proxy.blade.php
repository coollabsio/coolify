<div>
    <h2> Proxy </h2>

    @if($this->server->extra_attributes->proxy)
        <div class="mt-12">
            <div>
                Proxy type: {{ $this->server->extra_attributes->proxy  }}
            </div>
            <div class="mt-12"> Features in W11.</div>
            <ul>
                <li>Edit config file</li>
                <li>Enable dashboard (blocking port by firewall)</li>
                <li>Dashboard access - login/password</li>
                <li>Setup host for Traefik Dashboard</li>
                <li>Visit (nav to traefik dashboard)</li>
            </ul>
        </div>
    @else
        No proxy installed.
        <select wire:model="selectedProxy">
            <option value="{{ \App\Enums\ProxyTypes::TRAEFIK_V2 }}">
                {{ \App\Enums\ProxyTypes::TRAEFIK_V2 }}
            </option>
        </select>
        <button wire:click="runInstallProxy">Install Proxy</button>
    @endif

    <livewire:activity-monitor />

</div>
