<div>
    <x-application.settings-section id="cluster-application-runtime-options" title="Container options"
        helper="The v4 container settings. Coolify checks them on save. The next deploy applies them.">
        <form wire:submit="save" class="application-settings-form flex flex-col gap-4">
            @unless ($supported)
                <x-callout type="warning" title="Upgrade Sentinel to use the new options">
                    The options --init, --read-only, --pids-limit, --hostname, --entrypoint, --add-host, --cap-add,
                    --cap-drop, --security-opt, --sysctl, and container labels need Sentinel with
                    workload.runtime_options.v2 on every server of the application.
                </x-callout>
            @endunless

            <x-forms.input id="customDockerRunOptions" label="Custom Docker Options" canGate="update"
                :canResource="$workload" placeholder="--cap-add NET_BIND_SERVICE --init --ulimit nofile=65536:65536"
                helper="Supported: --shm-size, --ulimit, --tmpfs, -i, -t, --init, --read-only, --pids-limit, --hostname, --entrypoint, --add-host, --cap-drop, --security-opt no-new-privileges, --gpus, --cap-add and --sysctl from an allow list. NET_ADMIN, NET_RAW, SYS_PTRACE, SYS_NICE, SYS_RESOURCE, IPC_LOCK, net.ipv4.ip_forward, and the forwarding sysctls of an interface need a team admin. --privileged, --device, --ip, --dns, and --runtime are refused." />

            <x-forms.textarea id="customLabels" label="Container labels" rows="4" monospace canGate="update"
                :canResource="$workload" placeholder="com.example.team=web"
                helper="One key=value on each line, at most 64. Keys that start with coolify., io.containers., or io.podman. are refused." />
            @if ($proxyLabels)
                <x-callout type="info" title="Proxy labels are not used">
                    Ingress on servers of a cluster reads the domains from Coolify, not from Traefik or Caddy labels. Set the domains and the proxy options on the Domains page.
                </x-callout>
            @endif

            <div class="flex flex-col gap-3">
                <x-forms.checkbox id="gpuEnabled" label="GPU" canGate="update" :canResource="$workload"
                    helper="An NVIDIA GPU through CDI. Each server of the application must allow GPU workloads, which a team admin turns on on the server page. A GPU application stops its old container before the new one starts." />
                <div class="grid gap-4 lg:grid-cols-2" x-show="$wire.gpuEnabled" x-cloak>
                    <x-forms.input id="gpuCount" label="Count" placeholder="all" canGate="update" :canResource="$workload"
                        helper="A number or all. Coolify uses the first GPUs of each server." />
                    <x-forms.input id="gpuDeviceIds" label="Device IDs" placeholder="0,1" canGate="update"
                        :canResource="$workload" helper="GPU indexes or UUIDs, separated by commas. They replace the count." />
                </div>
            </div>

            <div class="grid gap-4 lg:grid-cols-2">
                <x-forms.textarea id="preDeploymentCommand" label="Pre-deployment command" rows="2" monospace
                    canGate="update" :canResource="$workload" placeholder="php artisan migrate --force"
                    helper="Runs with sh -c in the running container of the old revision, before the new containers start. It runs the code of the old revision. A failure stops the deploy. Without a running container, it is skipped." />
                <x-forms.textarea id="postDeploymentCommand" label="Post-deployment command" rows="2" monospace
                    canGate="update" :canResource="$workload" placeholder="php artisan cache:clear"
                    helper="Runs with sh -c in the new container after every server deployed. A failure is a warning in the deployment log." />
            </div>
            <x-forms.input type="number" id="deploymentCommandTimeout" label="Command timeout (seconds)" min="1"
                max="3600" canGate="update" :canResource="$workload" helper="1 to 3600 seconds, for each command." />

            @can('update', $workload)
                <div class="flex justify-end">
                    <x-forms.button type="submit" wire:target="save" isHighlighted>Save</x-forms.button>
                </div>
            @endcan
        </form>
    </x-application.settings-section>
</div>
