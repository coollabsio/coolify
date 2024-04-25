<div @class([
    'transition-all duration-150 box-without-bg dark:bg-coolgray-100 bg-white group',
    'hover:border-l-coollabs cursor-pointer' => !$upgrade,
    'hover:border-l-red-500 cursor-not-allowed' => $upgrade,
]) @if (!$upgrade) wire:click={{ $wire }} @endif>
    <div class="flex items-center">
        {{ $logo }}
        <div class="flex flex-col pl-2 ">
            <div class="dark:text-white text-md">
                {{ $title }}
            </div>
            @if ($upgrade)
                <div>{{ $upgrade }}</div>
            @else
                <div class="text-xs font-bold dark:text-neutral-500 group-hover:dark:text-neutral-300">
                    {{ $description }}
                </div>
            @endif
        </div>
    </div>
    @isset($documentation)
        <div class="flex-1"></div>
        <div class="flex items-center px-2 " title="Read the documentation.">
            <a class="p-2 rounded hover:bg-coolgray-200 hover:no-underline group-hover:dark:text-white text-neutral-600"
                onclick="event.stopPropagation()" href="{{ $documentation }}" target="_blank">
                Docs
            </a>
        </div>
    @endisset
</div>
