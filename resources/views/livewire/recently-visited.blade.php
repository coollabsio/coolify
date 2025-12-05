<div>
    @if(count($recentVisits) > 0)
        <div class="px-2 pb-4" x-data="{ expanded: true }">
            <button
                @click="expanded = !expanded"
                class="flex items-center gap-2 w-full text-neutral-500 dark:text-neutral-400 hover:text-neutral-700 dark:hover:text-white transition-colors py-1"
            >
                <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <span class="text-sm font-medium">Recents</span>
                <svg
                    xmlns="http://www.w3.org/2000/svg"
                    class="w-4 h-4 ml-auto transition-transform"
                    :class="{ 'rotate-180': !expanded }"
                    fill="none"
                    viewBox="0 0 24 24"
                    stroke="currentColor"
                >
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                </svg>
            </button>

            <ul x-show="expanded" x-collapse class="mt-1 space-y-0.5">
                @foreach($recentVisits as $visit)
                    <li>
                        <a
                            href="/{{ $visit->url }}"
                            class="flex flex-col px-2 py-1.5 text-sm rounded-sm hover:bg-neutral-200 dark:hover:bg-coolgray-100 transition-colors group"
                            title="{{ $visit->title }}{{ $visit->subtitle ? ' - ' . $visit->subtitle : '' }}"
                        >
                            <span class="font-medium text-black dark:text-white truncate">{{ $visit->title }}</span>
                            @if($visit->subtitle)
                                <span class="text-xs text-neutral-500 dark:text-neutral-400 truncate">{{ $visit->subtitle }}</span>
                            @endif
                        </a>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</div>
