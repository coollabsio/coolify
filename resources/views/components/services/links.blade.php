@if ($links->count() > 0)
    <div x-data="{
        dropdownOpen: false
    }" class="relative" @click.outside="dropdownOpen = false">
        <button @click="dropdownOpen=true"
            class="inline-flex items-center justify-center py-1 pr-12 text-sm font-medium transition-colors focus:outline-none disabled:opacity-50 disabled:pointer-events-none">
            <span class="flex flex-col items-start flex-shrink-0 h-full ml-2 leading-none translate-y-px">
                Open Application
            </span>
            <svg class="absolute right-0 w-5 h-5 mr-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round"
                    d="M8.25 15L12 18.75 15.75 15m-7.5-6L12 5.25 15.75 9" />
            </svg>
        </button>

        <div x-show="dropdownOpen" @click.away="dropdownOpen=false" x-transition:enter="ease-out duration-200"
            x-transition:enter-start="-translate-y-2" x-transition:enter-end="translate-y-0"
            class="absolute top-0 z-50 mt-6 min-w-max" x-cloak>
            <div class="p-1 mt-1 dark:bg-coolgray-200">
                @foreach ($links as $link)
                    <a class="dropdown-item" target="_blank" href="{{ $link }}">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" viewBox="0 0 24 24" stroke-width="1.5"
                            stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round">
                            <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                            <path d="M9 15l6 -6" />
                            <path d="M11 6l.463 -.536a5 5 0 0 1 7.071 7.072l-.534 .464" />
                            <path
                                d="M13 18l-.397 .534a5.068 5.068 0 0 1 -7.127 0a4.972 4.972 0 0 1 0 -7.071l.524 -.463" />
                        </svg>{{ $link }}
                    </a>
                @endforeach
            </div>
        </div>
    </div>
@endif
