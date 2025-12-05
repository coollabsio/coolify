<li x-data="{ expanded: localStorage.getItem('recents_expanded') !== 'false' }"
    x-init="$watch('expanded', value => localStorage.setItem('recents_expanded', value))">
    <button @click="expanded = !expanded"
        class="flex items-center justify-between w-full gap-3 px-2 py-1 text-sm dark:hover:bg-coolgray-100 dark:hover:text-white hover:bg-neutral-300">
        <span class="flex items-center gap-3">
            <svg class="icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                stroke="currentColor" stroke-width="1.5">
                <path stroke-linecap="round" stroke-linejoin="round"
                    d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
            </svg>
            Recents
        </span>
        <svg class="w-4 h-4 shrink-0 opacity-60 transition-transform duration-150"
            :class="expanded ? 'rotate-180' : ''"
            xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor"
            stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="m6 9 6 6 6-6" />
        </svg>
    </button>

    <ul x-show="expanded" x-collapse class="space-y-0.5 mt-1" wire:key="recents-list-{{ count($recents) }}">
        @forelse($recents as $index => $recent)
            <li wire:key="recent-{{ $index }}-{{ $recent['url'] }}" class="min-w-0">
                <div class="flex items-center gap-1 px-2 py-0.5 text-xs overflow-hidden dark:hover:bg-coolgray-100 dark:hover:text-white hover:bg-neutral-300">
                    <a href="/{{ $recent['url'] }}"
                        class="flex items-center gap-3 min-w-0 overflow-hidden flex-1"
                        title="{{ $recent['label'] }}{{ !empty($recent['sublabel']) ? ' - ' . $recent['sublabel'] : '' }}">
                        <span class="icon shrink-0"></span>
                        <span class="flex flex-col min-w-0 overflow-hidden">
                            <span class="truncate">{{ $recent['label'] }}</span>
                            @if (!empty($recent['sublabel']))
                                <span class="text-[10px] opacity-60 truncate">{{ $recent['sublabel'] }}</span>
                            @endif
                        </span>
                    </a>
                    <button wire:click.stop.throttle.500ms="togglePin('{{ $recent['url'] }}')"
                        class="shrink-0 p-0.5 hover:text-yellow-400 transition-colors"
                        title="{{ !empty($recent['pinned']) ? 'Unpin' : 'Pin' }}">
                        @if (!empty($recent['pinned']))
                            <svg class="w-3 h-3 text-yellow-400" fill="currentColor" viewBox="0 0 20 20">
                                <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/>
                            </svg>
                        @else
                            <svg class="w-3 h-3 opacity-40 hover:opacity-100" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.921-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"/>
                            </svg>
                        @endif
                    </button>
                </div>
            </li>
        @empty
            <li>
                <span class="flex items-center gap-3 px-2 py-0.5 text-xs opacity-50">
                    <span class="icon"></span>
                    No recent pages
                </span>
            </li>
        @endforelse
    </ul>
</li>
