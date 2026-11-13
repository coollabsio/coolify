@php
    $appliedUuid = $versions->firstWhere('hash', $server->proxy->last_applied_settings)?->uuid;
    $sourceLabels = ['ui' => 'Editor', 'api' => 'API', 'reset' => 'Reset', 'restore' => 'Restore', 'system' => 'Coolify'];
@endphp

<x-application.settings-section id="server-proxy-history-section" title="Configuration history"
    helper="Coolify keeps the last {{ \App\Models\ProxyConfigurationVersion::MAX_VERSIONS }} versions of the proxy configuration. Compare a version with the current configuration, or restore it. Restart the proxy to apply a restored version."
    flush>
    <div x-data="{
        diffOpen: false,
        diffLabel: '',
        {{-- Monaco objects stay out of Alpine state: a reactive proxy around the editor freezes the page. --}}
        diffHost() {
            return document.getElementById('proxy-configuration-diff-editor');
        },
        async openDiff(uuid) {
            const diff = await $wire.showConfigurationVersionDiff(uuid);
            if (!diff) {
                return;
            }
            this.diffLabel = diff.label;
            this.diffOpen = true;
            await this.$nextTick();
            {{-- The YAML editor of this page loads Monaco. --}}
            while (typeof window.monaco === 'undefined') {
                await new Promise((resolve) => setTimeout(resolve, 50));
            }
            this.closeEditor();
            const host = this.diffHost();
            host.diffEditor = monaco.editor.createDiffEditor(host, {
                readOnly: true,
                originalEditable: false,
                automaticLayout: true,
                renderSideBySide: true,
                minimap: { enabled: false },
                theme: document.documentElement.classList.contains('dark') ? 'coolify-dark' : 'vs',
                fontSize: '13px',
                scrollBeyondLastLine: false,
            });
            host.diffEditor.setModel({
                original: monaco.editor.createModel(diff.original, 'yaml'),
                modified: monaco.editor.createModel(diff.modified, 'yaml'),
            });
        },
        closeEditor() {
            const host = this.diffHost();
            if (!host?.diffEditor) {
                return;
            }
            const model = host.diffEditor.getModel();
            host.diffEditor.dispose();
            model?.original?.dispose();
            model?.modified?.dispose();
            host.diffEditor = null;
        },
    }" x-init="$watch('diffOpen', (open) => { if (!open) { closeEditor() } })"
        @keydown.window.escape="diffOpen = false">
        <div class="overflow-x-auto">
            <div class="data-table">
                <div class="data-table-header proxy-configuration-versions-table-grid">
                    <span>Saved</span>
                    <span>By</span>
                    <span>Source</span>
                    <span>Hash</span>
                    <span>Status</span>
                    <span class="text-right">Actions</span>
                </div>
                @foreach ($versions as $version)
                    @php $isCurrent = $loop->first; @endphp
                    <div wire:key="proxy-version-{{ $version->uuid }}"
                        class="data-table-row proxy-configuration-versions-table-grid border-b border-neutral-200 last:border-b-0 dark:border-white/[0.08]">
                        <div class="truncate text-[12px] text-neutral-950 dark:text-fg"
                            title="{{ $version->created_at->toIso8601String() }}">
                            {{ $version->created_at->format('Y-m-d H:i') }}
                        </div>
                        <div class="truncate text-[11px] text-neutral-600 dark:text-fg-dim">
                            {{ $version->user?->name ?? 'Coolify' }}
                        </div>
                        <div class="truncate text-[11px] text-neutral-600 dark:text-fg-dim">
                            {{ $sourceLabels[$version->source] ?? $version->source }}
                        </div>
                        <div class="font-mono text-[11px] text-neutral-600 dark:text-fg-dim">
                            {{ substr($version->hash, 0, 8) }}
                        </div>
                        <div class="flex min-w-0 flex-wrap items-center gap-2">
                            @if ($isCurrent)
                                <x-status-badge status="Current" type="success" size="sm" />
                            @endif
                            @if ($version->uuid === $appliedUuid)
                                <x-status-badge status="Applied" type="neutral" size="sm" />
                            @endif
                        </div>
                        <div class="flex items-center justify-end gap-1">
                            @unless ($isCurrent)
                                <button type="button" class="icon-button shrink-0" title="Compare with current"
                                    aria-label="Compare version {{ substr($version->hash, 0, 8) }} with the current configuration"
                                    @click="openDiff(@js($version->uuid))">
                                    <x-reicon name="code" class="size-3.5" />
                                </button>
                                <x-modal-confirmation title="Restore Proxy Configuration?"
                                    submitAction="restoreConfigurationVersion('{{ $version->uuid }}')" :actions="[
                                        'Save the version from ' . $version->created_at->format('Y-m-d H:i') . ' as the current proxy configuration.',
                                        'The current configuration stays in the history.',
                                        'Restart the proxy to apply it.',
                                    ]"
                                    step2ButtonText="Restore Configuration" :confirmWithText="false"
                                    :confirmWithPassword="false">
                                    <x-slot:trigger>
                                        <button type="button" class="icon-button shrink-0" title="Restore"
                                            aria-label="Restore version {{ substr($version->hash, 0, 8) }}">
                                            <x-reicon name="time-back" class="size-3.5" />
                                        </button>
                                    </x-slot:trigger>
                                </x-modal-confirmation>
                            @endunless
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        <div wire:ignore>
            <template x-teleport="body">
                <div x-show="diffOpen" x-cloak class="fixed inset-0 z-99 overflow-hidden">
                    <div class="absolute inset-0 h-full w-full bg-black/50 backdrop-blur-[2px]"></div>
                    <div @click.self="diffOpen = false"
                        class="relative flex min-h-full items-start justify-center p-2 sm:items-center sm:p-4">
                        <div class="application-settings-form application-settings-section relative flex max-h-[calc(100dvh-2rem)] w-full flex-col overflow-hidden lg:w-[95vw]! lg:max-w-7xl!"
                            style="box-shadow: 0 0 0 1px var(--coollabs-hairline), var(--shadow-modal)">
                            <header class="shrink-0">
                                <div class="min-w-0 flex-1 py-0.5">
                                    <h3 class="truncate">Compare configuration</h3>
                                    <p class="mt-0.5 text-xs text-neutral-500 dark:text-fg-dim">
                                        Left: version <span class="font-mono" x-text="diffLabel"></span>. Right: current
                                        configuration.
                                    </p>
                                </div>
                                <button type="button" @click="diffOpen = false" aria-label="Close"
                                    class="flex size-7 shrink-0 cursor-pointer items-center justify-center rounded-md text-neutral-500 transition-colors hover:bg-neutral-100 hover:text-black dark:text-fg-faint dark:hover:bg-white/[0.06] dark:hover:text-fg">
                                    <x-reicon name="x" class="size-4" />
                                </button>
                            </header>
                            <div id="proxy-configuration-diff-editor" class="h-[70vh] w-full"></div>
                        </div>
                    </div>
                </div>
            </template>
        </div>
    </div>
</x-application.settings-section>
