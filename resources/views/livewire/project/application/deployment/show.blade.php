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
        <div id="screen" :class="fullscreen ? 'fullscreen' : ''">
            <div @if ($isKeepAliveOn) wire:poll.2000ms="polling" @endif
                class="relative flex flex-col-reverse w-full p-2 px-4 mt-4 overflow-y-auto bg-white dark:text-white dark:bg-coolgray-100 scrollbar dark:border-coolgray-300"
                :class="fullscreen ? '' : 'max-h-[40rem] border border-dotted rounded'">
                <button title="Minimize" x-show="fullscreen" class="fixed top-4 right-4"
                    x-on:click="makeFullscreen"><svg class="icon" viewBox="0 0 24 24"
                        xmlns="http://www.w3.org/2000/svg">
                        <path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"
                            stroke-width="2" d="M6 14h4m0 0v4m0-4l-6 6m14-10h-4m0 0V6m0 4l6-6" />
                    </svg></button>
                <button title="Go Top" x-show="fullscreen" class="fixed top-4 right-28" x-on:click="goTop"> <svg
                        class="icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"
                            stroke-width="2" d="M12 5v14m4-10l-4-4M8 9l4-4" />
                    </svg></button>
                <button title="Follow Logs" x-show="fullscreen" :class="alwaysScroll ? 'dark:text-warning' : ''"
                    class="fixed top-4 right-16" x-on:click="toggleScroll"><svg class="icon" viewBox="0 0 24 24"
                        xmlns="http://www.w3.org/2000/svg">
                        <path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"
                            stroke-width="2" d="M12 5v14m4-4l-4 4m-4-4l4 4" />
                    </svg></button>

                <button title="Fullscreen" x-show="!fullscreen" class="absolute top-2 right-8"
                    x-on:click="makeFullscreen"><svg class="fixed icon" viewBox="0 0 24 24"
                        xmlns="http://www.w3.org/2000/svg">
                        <g fill="none">
                            <path
                                d="M24 0v24H0V0h24ZM12.593 23.258l-.011.002l-.071.035l-.02.004l-.014-.004l-.071-.035c-.01-.004-.019-.001-.024.005l-.004.01l-.017.428l.005.02l.01.013l.104.074l.015.004l.012-.004l.104-.074l.012-.016l.004-.017l-.017-.427c-.002-.01-.009-.017-.017-.018Zm.265-.113l-.013.002l-.185.093l-.01.01l-.003.011l.018.43l.005.012l.008.007l.201.093c.012.004.023 0 .029-.008l.004-.014l-.034-.614c-.003-.012-.01-.02-.02-.022Zm-.715.002a.023.023 0 0 0-.027.006l-.006.014l-.034.614c0 .012.007.02.017.024l.015-.002l.201-.093l.01-.008l.004-.011l.017-.43l-.003-.012l-.01-.01l-.184-.092Z" />
                            <path fill="currentColor"
                                d="M9.793 12.793a1 1 0 0 1 1.497 1.32l-.083.094L6.414 19H9a1 1 0 0 1 .117 1.993L9 21H4a1 1 0 0 1-.993-.883L3 20v-5a1 1 0 0 1 1.993-.117L5 15v2.586l4.793-4.793ZM20 3a1 1 0 0 1 .993.883L21 4v5a1 1 0 0 1-1.993.117L19 9V6.414l-4.793 4.793a1 1 0 0 1-1.497-1.32l.083-.094L17.586 5H15a1 1 0 0 1-.117-1.993L15 3h5Z" />
                        </g>
                    </svg></button>
                <div id="logs" class="flex flex-col font-mono">
                    @if (decode_remote_command_output($application_deployment_queue)->count() > 0)
                        @foreach (decode_remote_command_output($application_deployment_queue) as $line)
                            <span @class([
                                'dark:text-warning whitespace-pre-line' => $line['hidden'],
                                'text-red-500 font-bold whitespace-pre-line' => $line['type'] == 'stderr',
                            ])>[{{ $line['timestamp'] }}] @if ($line['hidden'])
                                    <br><br>[COMMAND] {{ $line['command'] }}<br>[OUTPUT]
                                    @endif @if (str($line['output'])->contains('http://') || str($line['output'])->contains('https://'))
                                        @php
                                            $line['output'] = preg_replace(
                                                '/(https?:\/\/[^\s]+)/',
                                                '<a href="$1" target="_blank" class="underline text-neutral-400">$1</a>',
                                                $line['output'],
                                            );
                                        @endphp {!! $line['output'] !!}
                                    @else
                                        {{ $line['output'] }}

                                    @endif
                            </span>
                        @endforeach
                    @else
                        <span class="font-mono text-neutral-400">No logs yet.</span>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
