@php
    $domainList = $workload->domains ?? [];
    $primaryDomain = $domainList[0] ?? null;
    $additionalDomainCount = max(0, count($domainList) - 1);
    $domainsUrl = route('project.cluster-application.domains', $routeParameters);
@endphp

<form wire:submit="saveDetails" class="flex flex-col gap-6">
    @can('update', $workload)
        <x-unsaved-bar action="saveDetails" targets="name,description" />
    @endcan

    <x-application.settings-section id="cluster-application-details" title="Application details"
        helper="Name the application. Renaming it does not change its internal hostname.">
        <div class="grid gap-4">
            <x-forms.input id="name" label="Name" required canGate="update" :canResource="$workload" />
            <x-forms.input id="description" label="Description" canGate="update" :canResource="$workload" />
        </div>
    </x-application.settings-section>
</form>

<x-application.settings-section id="cluster-application-access" title="Access"
    helper="How this application is reached publicly, and how other workloads in the cluster reach it.">
    <x-application.settings-subsection title="Public access" first>
        <div class="group relative flex items-center gap-3 rounded-lg border border-neutral-200 bg-neutral-50/60 px-4 py-3 transition-colors hover:bg-neutral-100 focus-within:ring-2 focus-within:ring-coollabs/40 dark:border-white/[0.07] dark:bg-white/[0.05] dark:hover:bg-white/[0.08] dark:focus-within:ring-brand-light/40">
            <a class="flex min-w-0 flex-1 items-center gap-3 after:absolute after:inset-0 after:content-[''] focus-visible:outline-none"
                aria-label="{{ $primaryDomain ? 'Manage application domains' : 'Add an application domain' }}"
                href="{{ $domainsUrl }}" {{ wireNavigate() }}>
                <div class="flex size-9 shrink-0 items-center justify-center rounded-md bg-neutral-200/70 text-neutral-600 dark:bg-white/[0.07] dark:text-fg-dim">
                    <x-reicon name="globe" class="size-4" />
                </div>
                <div class="min-w-0">
                    <p class="text-sm font-medium text-black dark:text-fg">
                        @if ($primaryDomain)
                            <span class="block truncate">{{ $primaryDomain }}</span>
                        @else
                            No public domain configured
                        @endif
                    </p>
                    <p class="text-xs text-neutral-500 dark:text-fg-dim">
                        @if ($additionalDomainCount > 0)
                            +{{ $additionalDomainCount }} more {{ Str::plural('domain', $additionalDomainCount) }}
                        @elseif (! $primaryDomain)
                            Make this application available from a URL
                        @else
                            Served by the ingress servers of the cluster
                        @endif
                    </p>
                </div>
            </a>
            <a class="button relative z-10 ml-auto shrink-0"
                aria-label="{{ $primaryDomain ? 'Manage application domains' : 'Add an application domain' }}"
                href="{{ $domainsUrl }}" {{ wireNavigate() }}>
                {{ $primaryDomain ? 'Manage domains' : 'Add domain' }}
                <x-reicon name="arrow-right" class="size-4" />
            </a>
        </div>
    </x-application.settings-subsection>

    <x-application.settings-subsection title="Internal access">
        <div class="grid gap-4 sm:grid-cols-2">
            @if ($internalHostname)
                <x-forms.copy-input label="Internal hostname" :text="$internalHostname" />
            @else
                <x-forms.copy-input label="Internal hostname" text="Pending" />
            @endif
        </div>
        <p class="mt-4 border-t border-neutral-200 pt-4 text-sm text-neutral-500 dark:border-white/[0.07] dark:text-fg-dim">
            Internal hostnames are only reachable by other workloads in the cluster.
        </p>
    </x-application.settings-subsection>
</x-application.settings-section>

<form wire:submit="saveConfiguration" class="flex flex-col gap-6">
    @can('update', $workload)
        <x-unsaved-bar action="saveConfiguration" targets="imageName,imageTag,startCommand" />
    @endcan

    <x-application.settings-section id="cluster-application-build" title="Build & deploy"
        helper="How Coolify deploys the application. Changes create a new revision. Redeploy the application to apply them.">
        <p class="text-sm text-neutral-500 dark:text-fg-dim">Nothing to build. This application deploys a prebuilt Docker image.</p>
        <x-application.settings-subsection title="Image">
            <div class="grid gap-4 lg:grid-cols-2">
                <x-forms.input id="imageName" label="Image" placeholder="nginx" required
                    canGate="update" :canResource="$workload" />
                <x-forms.input id="imageTag" label="Tag" placeholder="latest"
                    canGate="update" :canResource="$workload"
                    helper="Enter a tag (e.g., 'latest', 'v1.2.3') or SHA256 hash (e.g., '59e02939b1bf39f16c93138a28727aec520bb916da021180ae502c61626b3cf0')" />
            </div>
        </x-application.settings-subsection>
    </x-application.settings-section>

    <x-application.settings-section id="cluster-application-container" title="Container"
        helper="Options applied to the container when it starts. Changes create a new revision. Redeploy the application to apply them.">
        <x-forms.input id="startCommand" label="Start command" placeholder="nginx -g &quot;daemon off;&quot;"
            canGate="update" :canResource="$workload"
            helper="Overrides the image command. Use double quotes for arguments with spaces. Leave empty to use the image default." />
    </x-application.settings-section>
</form>
