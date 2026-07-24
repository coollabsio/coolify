{{-- @param array $card --}}
<a href="{{ $card['environment_uuid'] ? route('railway.canvas', ['project_uuid' => $card['uuid'], 'environment_uuid' => $card['environment_uuid']]) : '#' }}"
    @if ($card['environment_uuid']) wire:navigate @endif
    class="rw-node hover:rw-node-hover !p-0 overflow-hidden group">
    {{-- Title --}}
    <div class="px-4 py-3">
        <div class="text-[14px] font-semibold text-rw-text truncate">{{ $card['name'] }}</div>
    </div>

    {{-- Mini canvas preview --}}
    <div class="relative h-32 mx-3 rounded-lg rw-canvas-grid border overflow-hidden" style="border-color: var(--color-rw-border);">
        <div class="absolute inset-0 flex items-center justify-center gap-2">
            @forelse ($card['glyphs'] as $glyph)
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-lg border shadow-sm"
                    style="background: var(--color-rw-elevated); border-color: var(--color-rw-border-strong);">
                    <x-railway.resource-icon :type="$glyph" size="w-4 h-4" />
                </span>
            @empty
                <span class="text-[12px] text-rw-subtle">No services</span>
            @endforelse
        </div>
    </div>

    {{-- Footer --}}
    <div class="flex items-center gap-2 px-4 py-3 text-[12px] text-rw-muted">
        <span class="rw-dot {{ $card['online'] > 0 ? 'rw-dot-online' : 'rw-dot-offline' }}"></span>
        <span class="truncate">{{ $card['environment_name'] }}</span>
        <span class="text-rw-subtle">·</span>
        <span class="truncate">{{ $card['online'] }}/{{ $card['total'] }} services online</span>
    </div>
</a>
