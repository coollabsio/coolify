<div class="h-20 transition-all duration-150 cursor-pointer box-without-bg bg-coolgray-100 group hover:border-coollabs"
    wire:click={{ $wire }}>
    <div class="flex items-center">
        {{ $logo }}
        <div class="flex flex-col pl-2 ">
            <div class="text-white text-md">
                {{ $title }}
            </div>
            <div class="hidden text-xs font-bold text-neutral-500 group-hover:flex">
                {{ $description }}
            </div>
        </div>
    </div>
    @isset($documentation)
        <div class="flex-1"></div>
        <div class="flex items-center px-2 " title="Read the documentation.">
            <a class="p-2 rounded hover:bg-coolgray-200 hover:no-underline group-hover:text-white text-neutral-600"
                onclick="event.stopPropagation()" href="{{ $documentation }}" target="_blank">
                Docs
            </a>
        </div>
    @endisset
</div>
