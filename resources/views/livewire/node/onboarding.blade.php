<div class="application-settings-form mx-auto w-full max-w-3xl">
    <x-slot:title>Add Node | Coolify</x-slot>

    <header class="mb-5">
        <a href="{{ route('node-cluster.index') }}" {{ wireNavigate() }} class="mb-2 inline-flex items-center gap-1 text-[12px] text-neutral-500 hover:text-black dark:text-fg-dim dark:hover:text-fg">
            <x-reicon name="arrow-left" class="size-3.5" /> Clusters
        </a>
        <h1 class="text-[24px]! leading-7! font-semibold! tracking-tight!">Add Node</h1>
        <p class="mt-1 text-[13px] text-neutral-500 dark:text-fg-dim">Connect a server. Coolify installs and configures the required components.</p>
    </header>

    <ol class="mb-6 grid grid-cols-3 overflow-hidden rounded-xl border border-neutral-200 bg-white dark:border-white/[0.08] dark:bg-white/[0.04]">
        @foreach ([1 => 'Connect', 2 => 'Install', 3 => 'Ready'] as $number => $label)
            <li class="flex items-center gap-2 border-r border-neutral-200 px-3 py-3 last:border-r-0 dark:border-white/[0.08] {{ $step >= $number ? 'text-black dark:text-fg' : 'text-neutral-400 dark:text-fg-faint' }}">
                <span class="flex size-6 shrink-0 items-center justify-center rounded-full text-[11px] font-semibold {{ $step >= $number ? 'bg-coollabs text-white' : 'bg-neutral-100 dark:bg-white/[0.06]' }}">{{ $number }}</span>
                <span class="text-[12px] font-semibold">{{ $label }}</span>
            </li>
        @endforeach
    </ol>

    @if ($step === 1)
        <form wire:submit="connect" class="rounded-xl border border-neutral-200 bg-white p-5 shadow-sm dark:border-white/[0.08] dark:bg-white/[0.04]">
            <div class="mb-4"><h2 class="text-[15px]! font-semibold!">Connect your server</h2><p class="mt-1 text-[12px] text-neutral-500 dark:text-fg-dim">Use a fresh Linux server that Coolify can reach through SSH.</p></div>
            <div class="grid gap-4 sm:grid-cols-2">
                <x-forms.input wire:model="ip" label="IP address" placeholder="203.0.113.10" required />
                <x-forms.input wire:model="name" label="Node name" required />
                <div class="sm:col-span-2">
                    <x-forms.select wire:model="privateKeyId" label="SSH private key" required>
                        <option value="">Select a private key</option>
                        @foreach ($privateKeys as $privateKey)<option value="{{ $privateKey->id }}">{{ $privateKey->name }}</option>@endforeach
                    </x-forms.select>
                    @if ($privateKeys->isEmpty())<a href="{{ route('security.private-key.index') }}" {{ wireNavigate() }} class="mt-2 inline-block text-[12px]">Add an SSH private key</a>@endif
                </div>
            </div>
            <details class="mt-4 rounded-lg border border-neutral-200 dark:border-white/[0.08]">
                <summary class="cursor-pointer px-3 py-2.5 text-[12px] font-medium">Advanced connection settings</summary>
                <div class="grid gap-4 border-t border-neutral-200 p-3 dark:border-white/[0.08] sm:grid-cols-2">
                    <x-forms.input wire:model="user" label="SSH user" required />
                    <x-forms.input wire:model="port" type="number" label="SSH port" required />
                    <div class="sm:col-span-2"><x-forms.input wire:model="coolifyUrl" label="Coolify callback URL" helper="The Node uses this URL to connect to Coolify." required /></div>
                </div>
            </details>
            @if ($errors->any())
                <div role="alert" class="mt-3 rounded-lg border border-red-500/20 bg-red-500/10 p-3 text-[12px] text-red-700 dark:text-red-300">{{ $errors->first() }}</div>
            @endif
            <div class="mt-5 flex justify-end"><x-forms.button type="submit" isHighlighted wire:loading.attr="disabled"><span wire:loading.remove wire:target="connect">Connect</span><span wire:loading wire:target="connect">Checking connection...</span></x-forms.button></div>
        </form>
    @elseif ($step === 2)
        <form wire:submit="install" class="rounded-xl border border-neutral-200 bg-white p-5 shadow-sm dark:border-white/[0.08] dark:bg-white/[0.04]">
            <div class="mb-4 flex items-start gap-3"><span class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-emerald-500/10 text-emerald-600 dark:text-emerald-400"><x-reicon name="check-circle" class="size-4" /></span><div><h2 class="text-[15px]! font-semibold!">Server connected</h2><p class="mt-0.5 text-[12px] text-neutral-500 dark:text-fg-dim">Review the cluster. Coolify will install missing components.</p></div></div>
            @if ($clusters->isNotEmpty())
                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="flex cursor-pointer gap-2 rounded-lg border p-3 text-[12px] dark:border-white/[0.08]"><input type="radio" wire:model.live="clusterMode" value="existing"><span><strong class="block">Existing cluster</strong><span class="text-neutral-500">Add this Node to a cluster.</span></span></label>
                    <label class="flex cursor-pointer gap-2 rounded-lg border p-3 text-[12px] dark:border-white/[0.08]"><input type="radio" wire:model.live="clusterMode" value="new"><span><strong class="block">New cluster</strong><span class="text-neutral-500">Create a private cluster.</span></span></label>
                </div>
            @endif
            <div class="mt-4">
                @if ($clusterMode === 'existing' && $clusters->isNotEmpty())
                    <x-forms.select wire:model="clusterUuid" label="Cluster" required><option value="">Select a cluster</option>@foreach ($clusters as $cluster)<option value="{{ $cluster->uuid }}">{{ $cluster->name }}</option>@endforeach</x-forms.select>
                @else
                    <x-forms.input wire:model="clusterName" label="Cluster name" required />
                @endif
            </div>
            <details class="mt-4 rounded-lg border border-neutral-200 dark:border-white/[0.08]"><summary class="cursor-pointer px-3 py-2.5 text-[12px] font-medium">Technical details</summary><dl class="grid grid-cols-2 gap-3 border-t border-neutral-200 p-3 text-[12px] dark:border-white/[0.08] sm:grid-cols-3">@foreach (['hostname' => 'Hostname', 'os' => 'Operating system', 'arch' => 'Architecture', 'cpus' => 'CPU cores', 'package_manager' => 'Package manager'] as $key => $label)<div><dt class="text-neutral-500 dark:text-fg-faint">{{ $label }}</dt><dd class="mt-0.5 font-medium">{{ data_get($inspection, $key, 'Unknown') }}</dd></div>@endforeach<div><dt class="text-neutral-500 dark:text-fg-faint">Podman</dt><dd class="mt-0.5 font-medium">{{ data_get($inspection, 'podman_installed') ? 'Installed' : 'Will be installed' }}</dd></div></dl></details>
            <div class="mt-5 flex justify-end"><x-forms.button type="submit" isHighlighted wire:loading.attr="disabled"><span wire:loading.remove wire:target="install">Install Node</span><span wire:loading wire:target="install">Starting...</span></x-forms.button></div>
        </form>
    @else
        @php
            $currentStep = data_get($onboarding, 'step', 'queued'); $status = data_get($onboarding, 'status', 'queued');
            $steps = ['preparing' => ['Preparing server', 'Checking the host and packages'], 'installing' => ['Installing Node components', 'Installing Podman and Sentinel'], 'connecting' => ['Connecting to Coolify', 'Starting the secure control channel'], 'networking' => ['Configuring network', 'Joining the private cluster mesh'], 'verifying' => ['Running final checks', 'Checking the Node connection']];
            $keys = array_keys($steps); $currentIndex = array_search($currentStep, $keys, true); $currentIndex = $currentIndex === false ? -1 : $currentIndex;
        @endphp
        <section @if (!in_array($status, ['ready', 'failed'])) wire:poll.2s="refreshStatus" @endif class="rounded-xl border border-neutral-200 bg-white p-5 shadow-sm dark:border-white/[0.08] dark:bg-white/[0.04]">
            <div class="mb-4"><h2 class="text-[15px]! font-semibold!">{{ $status === 'ready' ? 'Your Node is ready' : ($status === 'failed' ? 'Installation needs attention' : 'Installing your Node') }}</h2><p class="mt-1 text-[12px] text-neutral-500 dark:text-fg-dim">{{ $status === 'ready' ? $node?->name.' is connected to '.$node?->cluster?->name.'.' : ($status === 'failed' ? 'Installation stopped at the highlighted step. Review the details and retry.' : 'You can leave this page. Installation continues in the background.') }}</p></div>
            <div class="divide-y divide-neutral-200 overflow-hidden rounded-lg border border-neutral-200 dark:divide-white/[0.08] dark:border-white/[0.08]">
                @foreach ($steps as $key => [$title, $description])
                    @php $index = array_search($key, $keys, true); $itemStatus = $status === 'ready' || $index < $currentIndex ? 'success' : ($status === 'failed' && $key === $currentStep ? 'error' : ($key === $currentStep ? 'running' : 'pending')); @endphp
                    <x-checkpoint-item :title="$title" :description="$description" :status="$itemStatus" />
                @endforeach
            </div>
            @if ($status === 'failed')
                <div class="mt-4 rounded-lg border border-red-500/20 bg-red-500/10 p-3 text-[12px] text-red-700 dark:text-red-300">{{ data_get($onboarding, 'error') }}</div>
                <details class="mt-3 rounded-lg border border-neutral-200 dark:border-white/[0.08]">
                    <summary class="cursor-pointer px-3 py-2.5 text-[12px] font-semibold">Technical details</summary>
                    <div class="border-t border-neutral-200 p-3 dark:border-white/[0.08]">
                        <dl class="grid grid-cols-1 gap-3 text-[12px] sm:grid-cols-2">
                            <div><dt class="text-neutral-500 dark:text-fg-faint">Failed step</dt><dd class="mt-0.5 font-medium">{{ data_get($steps, $currentStep.'.0', str($currentStep)->replace('_', ' ')->title()) }}</dd></div>
                            <div><dt class="text-neutral-500 dark:text-fg-faint">Node</dt><dd class="mt-0.5 break-all font-medium">{{ $node?->name }} ({{ $node?->ip }})</dd></div>
                            <div><dt class="text-neutral-500 dark:text-fg-faint">Operating system</dt><dd class="mt-0.5 font-medium">{{ data_get($node?->metadata, 'os', 'Unknown') }}</dd></div>
                            <div><dt class="text-neutral-500 dark:text-fg-faint">Last update</dt><dd class="mt-0.5 font-medium">{{ data_get($onboarding, 'updated_at', 'Unknown') }}</dd></div>
                        </dl>
                        <div class="mt-3">
                            <p class="mb-1.5 text-[11px] font-medium text-neutral-500 dark:text-fg-faint">Error output</p>
                            <pre class="max-h-64 overflow-auto whitespace-pre-wrap break-words rounded-md bg-neutral-100 p-3 font-mono text-[11px] leading-5 text-neutral-800 select-text dark:bg-black/30 dark:text-fg-dim">{{ data_get($onboarding, 'technical_error', 'No technical error output was recorded.') }}</pre>
                        </div>
                    </div>
                </details>
                <div class="mt-5 flex justify-end"><x-forms.button wire:click="retry" isHighlighted wire:loading.attr="disabled">Retry installation</x-forms.button></div>
            @elseif ($status === 'ready')
                <div class="mt-5 flex flex-wrap justify-end gap-2"><a href="{{ route('node.show', ['node_uuid' => $node?->uuid]) }}" {{ wireNavigate() }} class="button">View Node</a><a href="{{ route('project.index') }}" {{ wireNavigate() }} class="button button-highlighted">Deploy an application</a></div>
            @endif
        </section>
    @endif
</div>
