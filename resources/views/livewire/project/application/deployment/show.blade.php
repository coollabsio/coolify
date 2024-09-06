<div>
    <x-slot:title>
        {{ data_get_str($application, 'name')->limit(10) }} > Deployment | Coolify
    </x-slot>
    <h1 class="py-0">Deployment</h1>
    <livewire:project.shared.configuration-checker :resource="$application" />
    <livewire:project.application.heading :application="$application" />
    <div class="pt-4" x-data="{
        fullscreen: false,
        alwaysScroll: false,
        intervalId: null,
        showTimestamps: true,
        makeFullscreen() {
            this.fullscreen = !this.fullscreen;
            if (this.fullscreen === false) {
                this.alwaysScroll = false;
                clearInterval(this.intervalId);
            }
        },
        toggleScroll() {
            this.alwaysScroll = !this.alwaysScroll;

            if (this.alwaysScroll) {
                this.intervalId = setInterval(() => {
                    const screen = document.getElementById('screen');
                    const logs = document.getElementById('logs');
                    if (screen.scrollTop !== logs.scrollHeight) {
                        screen.scrollTop = logs.scrollHeight;
                    }
                }, 100);
            } else {
                clearInterval(this.intervalId);
                this.intervalId = null;
            }
        },
        goTop() {
            this.alwaysScroll = false;
            clearInterval(this.intervalId);
            const screen = document.getElementById('screen');
            screen.scrollTop = 0;
        }
    }">
        <livewire:project.application.deployment-navbar :application_deployment_queue="$application_deployment_queue" />
        @if (data_get($application_deployment_queue, 'status') === 'in_progress')
            <div class="flex items-center gap-1 pt-2 ">Deployment is
                <div class="dark:text-warning">
                    {{ Str::headline(data_get($this->application_deployment_queue, 'status')) }}.
                </div>
                <x-loading class="loading-ring" />
            </div>
            {{-- <div class="">Logs will be updated automatically.</div> --}}
        @else
            <div class="pt-2 ">Deployment is <span
                    class="dark:text-warning">{{ Str::headline(data_get($application_deployment_queue, 'status')) }}</span>.
            </div>
        @endif
        <div id="screen" :class="fullscreen ? 'fullscreen' : 'relative'">
            <div @if ($isKeepAliveOn) wire:poll.2000ms="polling" @endif
                class="flex flex-col-reverse w-full p-2 px-4 mt-4 overflow-y-auto bg-white dark:text-white dark:bg-coolgray-100 scrollbar dark:border-coolgray-300"
                :class="fullscreen ? '' : 'min-h-14 max-h-[40rem] border border-dotted rounded'">
                <div :class="fullscreen ? 'fixed' : 'absolute'" class="top-4 right-6">
                    <div class="flex justify-end gap-4 fixed -translate-x-full">
                        <button title="Toggle timestamps" x-on:click="showTimestamps = !showTimestamps">
                            <svg class="icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" fill="none"
                                stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                    d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                            </svg>
                        </button>
                        <button title="Go Top" x-show="fullscreen" x-on:click="goTop">
                            <svg class="icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                <path fill="none" stroke="currentColor" stroke-linecap="round"
                                    stroke-linejoin="round" stroke-width="2" d="M12 5v14m4-10l-4-4M8 9l4-4" />
                            </svg>
                        </button>
                        <button title="Follow Logs" x-show="fullscreen" :class="alwaysScroll ? 'dark:text-warning' : ''"
                            x-on:click="toggleScroll">
                            <svg class="icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                <path fill="none" stroke="currentColor" stroke-linecap="round"
                                    stroke-linejoin="round" stroke-width="2" d="M12 5v14m4-4l-4 4m-4-4l4 4" />
                            </svg>
                        </button>
                        <button title="Fullscreen" x-show="!fullscreen" x-on:click="makeFullscreen">
                            <svg class="icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                <g fill="none">
                                    <path
                                        d="M24 0v24H0V0h24ZM12.593 23.258l-.011.002l-.071.035l-.02.004l-.014-.004l-.071-.035c-.01-.004-.019-.001-.024.005l-.004.01l-.017.428l.005.02l.01.013l.104.074l.015.004l.012-.004l.104-.074l.012-.016l.004-.017l-.017-.427c-.002-.01-.009-.017-.017-.018Zm.265-.113l-.013.002l-.185.093l-.01.01l-.003.011l.018.43l.005.012l.008.007l.201.093c.012.004.023 0 .029-.008l.004-.014l-.034-.614c-.003-.012-.01-.02-.02-.022Zm-.715.002a.023.023 0 0 0-.027.006l-.006.014l-.034.614c0 .012.007.02.017.024l.015-.002l.201-.093l.01-.008l.004-.011l.017-.43l-.003-.012l-.01-.01l-.184-.092Z" />
                                    <path fill="currentColor"
                                        d="M9.793 12.793a1 1 0 0 1 1.497 1.32l-.083.094L6.414 19H9a1 1 0 0 1 .117 1.993L9 21H4a1 1 0 0 1-.993-.883L3 20v-5a1 1 0 0 1 1.993-.117L5 15v2.586l4.793-4.793ZM20 3a1 1 0 0 1 .993.883L21 4v5a1 1 0 0 1-1.993.117L19 9V6.414l-4.793 4.793a1 1 0 0 1-1.497-1.32l.083-.094L17.586 5H15a1 1 0 0 1-.117-1.993L15 3h5Z" />
                                </g>
                            </svg>
                        </button>
                        <button title="Minimize" x-show="fullscreen" x-on:click="makeFullscreen">
                            <svg class="icon" viewBox="0 0 24 24"xmlns="http://www.w3.org/2000/svg">
                                <path fill="none" stroke="currentColor" stroke-linecap="round"
                                    stroke-linejoin="round" stroke-width="2"
                                    d="M6 14h4m0 0v4m0-4l-6 6m14-10h-4m0 0V6m0 4l6-6" />
                            </svg>
                        </button>
                    </div>
                </div>

                <div id="logs" class="flex flex-col font-mono">
                    @forelse ($this->logLines as $line)
                        <div @class([
                            'mt-2' => $line['command'] ?? false,
                            'flex gap-2 dark:hover:bg-coolgray-500 hover:bg-gray-100',
                        ])>
                            <span x-show="showTimestamps" class="shrink-0 text-gray-500">{{ $line['timestamp'] }}</span>
                            <span @class([
                                'text-coollabs dark:text-warning' => $line['hidden'],
                                'text-red-500' => $line['stderr'],
                                'font-bold' => $line['command'] ?? false,
                                'whitespace-pre-wrap',
                            ])>{!! $line['line'] !!}</span>
                        </div>
                    @empty
                        <span class="font-mono text-neutral-400 mb-2">No logs yet.</span>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
</div>
