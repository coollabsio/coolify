<div class="flex flex-col gap-6" x-init="$wire.loadImages">
    <x-application.settings-section title="Rollback images"
        helper="Keep previously built Docker images available for fast rollbacks. Rollback uses an existing image without rebuilding the application. Images in the Docker registry are pulled to the server first." flush>
        <x-slot:actions>
            @can('view', $application)
                <x-forms.button wire:click="loadImages(true)">
                    Reload images
                </x-forms.button>
            @endcan
        </x-slot:actions>

        <form wire:submit="saveSettings"
            class="flex flex-col gap-4 border-b border-neutral-200 px-4 py-3.5 dark:border-white/[0.07]">
            <x-unsaved-bar action="saveSettings" />
            @if ($serverRetentionDisabled)
                <x-callout type="warning" title="Disabled by the server">
                    Image retention is disabled at the server level. This setting has no effect until a server
                    administrator enables it.
                </x-callout>
            @endif

            <div class="max-w-sm">
                <x-forms.input id="dockerImagesToKeep" type="number" min="0" max="100"
                    label="Images to keep"
                    helper="Set to 0 to keep only the running image. Pull request images are always removed during cleanup."
                    canGate="update" :canResource="$application" :disabled="$serverRetentionDisabled" />
            </div>
        </form>

        <div wire:target="loadImages" wire:loading.remove>
            @forelse ($images as $image)
                @php
                    $tag = data_get($image, 'tag');
                    $date = data_get($image, 'created_at');
                    $createdAt = \Illuminate\Support\Carbon::parse($date);
                    $isCommitSha = preg_match('/^[0-9a-f]{7,128}$/i', $tag);
                    $isPrTag = preg_match('/^pr-\d+$/', $tag);
                    $isRollbackable = $isCommitSha || $isPrTag;
                    $isCurrent = data_get($image, 'is_current');
                    $isRegistryImage = data_get($image, 'source') === 'registry';
                @endphp
                <div
                    class="flex flex-col gap-3 border-b border-neutral-200 px-4 py-3.5 last:border-b-0 sm:flex-row sm:items-center dark:border-white/[0.07]">
                    <div
                        class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-neutral-100 text-neutral-500 ring-1 ring-neutral-200 dark:bg-white/[0.05] dark:text-fg-dim dark:ring-white/[0.07]">
                        <x-reicon name="layers" class="size-[18px]" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <code class="truncate font-mono text-[13px] font-semibold text-black dark:text-fg">
                                {{ $tag }}
                            </code>
                            @if ($isCurrent)
                                <x-status-badge status="Running image" type="success" />
                            @elseif (!$isRollbackable)
                                <x-status-badge status="Rollback unavailable" type="neutral" />
                            @endif
                            @if ($isRegistryImage)
                                <x-status-badge status="In registry" type="neutral" />
                            @endif
                        </div>
                        <p class="mt-1 text-xs text-neutral-500 dark:text-fg-dim">
                            {{ $isRegistryImage ? 'Deployed' : 'Built' }} {{ $createdAt->diffForHumans() }}
                            <span class="mx-1 text-neutral-300 dark:text-fg-faint">·</span>
                            {{ $date }}
                        </p>
                    </div>
                    @can('deploy', $application)
                        @if ($isCurrent)
                            <x-forms.button disabled tooltip="This image is currently running.">
                                Rollback
                            </x-forms.button>
                        @elseif (!$isRollbackable)
                            <x-forms.button disabled
                                tooltip="Only commit and pull-request image tags support rollback.">
                                Rollback
                            </x-forms.button>
                        @else
                            <x-forms.button wire:click="rollbackImage('{{ $tag }}')">
                                Roll back to this image
                            </x-forms.button>
                        @endif
                    @endcan
                </div>
            @empty
                <x-empty title="No rollback images"
                    description="No previous application images are stored on this server or in the Docker registry."
                    icon-name="layers" />
            @endforelse
        </div>

        <div class="w-full" wire:target="loadImages" wire:loading>
            <x-skeleton.list :rows="3" label="Loading available images" />
        </div>
    </x-application.settings-section>
</div>
