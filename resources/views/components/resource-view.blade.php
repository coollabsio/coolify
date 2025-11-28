 <div @class([
    'coolbox group',
    '!cursor-not-allowed hover:border-l-red-500' => $upgrade,
 ])>
    <div class="flex items-center">
        <div class="w-[4.5rem] h-[4.5rem] flex items-center justify-center text-black dark:text-white shrink-0 rounded-lg overflow-hidden">
            {{ $logo }}
        </div>
        <div class="flex flex-col pl-3 space-y-1">
            <div class="dark:text-white text-md font-medium">
                {{ $title }}
            </div>
            @if ($upgrade)
                <div>{{ $upgrade }}</div>
            @else
                <div class="text-xs dark:text-neutral-400 dark:group-hover:text-neutral-200 line-clamp-2">
                    {{ $description }}
                </div>
            @endif
        </div>
    </div>
</div>
