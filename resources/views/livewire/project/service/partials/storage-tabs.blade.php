{{-- Tabs of the storage card. A tab loads on hover, and a loaded tab opens without a request. --}}
@php
    $tabs = [
        'volumes' => ['label' => 'Volumes', 'count' => $this->volumeCount],
        'files' => ['label' => 'Files', 'count' => $this->fileCount],
        'directories' => ['label' => 'Directories', 'count' => $this->directoryCount],
    ];
@endphp

<div x-data="{ loadingTab: null }"
    class="inline-flex items-center gap-0.5 rounded-lg bg-neutral-100 p-1 dark:bg-white/[0.04]">
    @foreach ($tabs as $tab => $meta)
        <button type="button" @disabled($meta['count'] === 0)
            @mouseenter="if (! $wire.loadedTabs.includes(@js($tab))) $wire.preloadTab(@js($tab))"
            @click="if ($wire.loadedTabs.includes(@js($tab))) { $wire.activeTab = @js($tab) } else { loadingTab = @js($tab); $wire.setActiveTab(@js($tab)).finally(() => loadingTab = null) }"
            :class="$wire.activeTab === @js($tab) ? @js($tabButtonActive) : @js($tabButtonInactive)"
            class="{{ $tabButtonBase }} inline-flex items-center gap-1.5">
            <x-loading compact x-show="loadingTab === '{{ $tab }}'" x-cloak />
            {{ $meta['label'] }} ({{ $meta['count'] }})
        </button>
    @endforeach
</div>
