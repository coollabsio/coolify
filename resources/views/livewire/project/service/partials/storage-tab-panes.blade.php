{{-- One pane for each loaded tab. Alpine shows the active pane, so a loaded tab opens without a request. --}}
@php
    $isComposeManaged = $resource->type() === 'service' || data_get($resource, 'build_pack') === 'dockercompose';
    $hasPreviewSuffix = $resource instanceof \App\Models\Application && $resource->git_based() && filled($resource->git_repository);
    $panes = [
        'volumes' => ['has' => $this->volumeCount > 0],
        'files' => [
            'has' => $this->fileCount > 0,
            'storages' => $this->files,
            'notice' => 'Docker Compose file mounts are read-only here, but you can edit their content. Edit the compose file and reload it to change mounts.',
            'emptyTitle' => 'No file mounts configured',
            'emptyIcon' => 'file',
        ],
        'directories' => [
            'has' => $this->directoryCount > 0,
            'storages' => $this->directories,
            'notice' => 'Docker Compose directory mounts are read-only here. Edit the compose file and reload it to change directories.',
            'emptyTitle' => 'No directory mounts configured',
            'emptyIcon' => 'folder',
        ],
    ];
@endphp

@foreach ($panes as $tab => $pane)
    @if (in_array($tab, $loadedTabs, true))
        <div wire:key="storage-pane-{{ $tab }}" x-show="$wire.activeTab === @js($tab)"
            @if ($activeTab !== $tab) style="display: none" @endif>
            @if ($tab === 'volumes')
                @if ($pane['has'])
                    <livewire:project.shared.storages.all wire:key="{{ $volumesKey }}" :resource="$resource" />
                @else
                    <x-empty size="sm" title="No volumes configured" :description="$emptyVolumesDescription"
                        icon-name="storages" />
                @endif
            @elseif ($pane['has'])
                @if ($isComposeManaged)
                    <div
                        class="border-b border-neutral-200 px-4 py-3 text-[13px] leading-5 text-amber-800 dark:border-white/[0.08] dark:text-amber-300/90">
                        {{ $pane['notice'] }}
                    </div>
                @endif
                <div class="data-table w-full">
                    <div @class([
                        'data-table-header',
                        $hasPreviewSuffix ? 'file-mounts-table-grid-with-pr' : 'file-mounts-table-grid',
                    ])>
                        <span>Source Path</span>
                        <span>Destination Path</span>
                        @if ($hasPreviewSuffix)
                            <div class="flex items-center gap-1.5">
                                <span>PR suffix</span>
                                <x-helper helper="Adds -pr-N to the path so each preview uses isolated files. Disabling it shares the production files with previews." />
                            </div>
                        @endif
                        <span>Status</span>
                        <span class="text-right">Actions</span>
                    </div>
                    @foreach ($pane['storages'] as $fs)
                        <livewire:project.service.file-storage :fileStorage="$fs"
                            wire:key="{{ $tab }}-{{ $fs->id }}" />
                    @endforeach
                </div>
            @else
                <x-empty size="sm" :title="$pane['emptyTitle']" :description="$emptyMountsDescription"
                    :icon-name="$pane['emptyIcon']" />
            @endif
        </div>
    @endif
@endforeach
