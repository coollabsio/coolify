<div class="p-4 my-4 border dark:border-coolgray-200 border-neutral-200">
    <div x-init="$wire.getLogs" id="screen" x-data="{
        fullscreen: false,
        useSimpleView: localStorage.getItem('logView') === 'simple',
        makeFullscreen() {
            this.fullscreen = !this.fullscreen;
        },
        toggleLogView() {
            localStorage.setItem('logView', this.useSimpleView ? 'simple' : 'enhanced');
        }
    }">
        <div class="flex gap-2 items-center">
            @if ($displayName)
                <h4>{{ $displayName }}</h4>
            @elseif ($resource?->type() === 'application' || str($resource?->type())->startsWith('standalone'))
                <h4>{{ $container }}</h4>
            @else
                <h4>{{ str($container)->beforeLast('-')->headline() }}</h4>
            @endif
            @if ($pull_request)
                <div>({{ $pull_request }})</div>
            @endif
            @if ($streamLogs)
                <x-loading wire:poll.2000ms='getLogs(true)' />
            @endif
        </div>
        <form wire:submit='getLogs(true)' class="flex flex-col gap-4">
            <div class="w-full sm:w-96">
                <x-forms.input label="Only Show Number of Lines" placeholder="100" type="number" required
                    id="numberOfLines" :readonly="$streamLogs"></x-forms.input>
            </div>
            <div class="flex flex-col gap-4 sm:flex-row sm:flex-wrap sm:gap-2 sm:items-center">
                <x-forms.button type="submit">Refresh</x-forms.button>
                <x-forms.checkbox instantSave label="Stream Logs" id="streamLogs"></x-forms.checkbox>
                <x-forms.checkbox instantSave label="Include Timestamps" id="showTimeStamps"></x-forms.checkbox>
                <x-forms.checkbox-alpine label="Simple View" x-model="useSimpleView"
                    @click="$nextTick(() => toggleLogView())" />
            </div>
        </form>
        <div :class="fullscreen ? 'fullscreen' : 'relative w-full py-4 mx-auto'">
            <div class="flex overflow-y-auto overflow-x-hidden flex-col-reverse px-4 py-2 w-full min-w-0 bg-white dark:text-white dark:bg-coolgray-100 scrollbar dark:border-coolgray-300 border-neutral-200"
                :class="fullscreen ? '' : 'max-h-96 border border-solid rounded-sm'">
                <div :class="fullscreen ? 'fixed top-4 right-4' : 'absolute top-6 right-0'">
                    <div class="flex justify-end gap-4" :class="fullscreen ? 'fixed' : ''"
                        style="transform: translateX(-100%)">
                        <button title="Fullscreen" x-show="!fullscreen" x-on:click="makeFullscreen">
                            <svg class="w-5 h-5 opacity-30 hover:opacity-100" viewBox="0 0 24 24"
                                xmlns="http://www.w3.org/2000/svg">
                                <g fill="none">
                                    <path
                                        d="M24 0v24H0V0h24ZM12.593 23.258l-.011.002l-.071.035l-.02.004l-.014-.004l-.071-.035c-.01-.004-.019-.001-.024.005l-.004.01l-.017.428l.005.02l.01.013l.104.074l.015.004l.012-.004l.104-.074l.012-.016l.004-.017l-.017-.427c-.002-.01-.009-.017-.017-.018Zm.265-.113l-.013.002l-.185.093l-.01.01l-.003.011l.018.43l.005.012l.008.007l.201.093c.012.004.023 0 .029-.008l.004-.014l-.034-.614c-.003-.012-.01-.02-.02-.022Zm-.715.002a.023.023 0 0 0-.027.006l-.006.014l-.034.614c0 .012.007.02.017.024l.015-.002l.201-.093l.01-.008l.004-.011l.017-.43l-.003-.012l-.01-.01l-.184-.092Z" />
                                    <path fill="currentColor"
                                        d="M9.793 12.793a1 1 0 0 1 1.497 1.32l-.083.094L6.414 19H9a1 1 0 0 1 .117 1.993L9 21H4a1 1 0 0 1-.993-.883L3 20v-5a1 1 0 0 1 1.993-.117L5 15v2.586l4.793-4.793ZM20 3a1 1 0 0 1 .993.883L21 4v5a1 1 0 0 1-1.993.117L19 9V6.414l-4.793 4.793a1 1 0 0 1-1.497-1.32l.083-.094L17.586 5H15a1 1 0 0 1-.117-1.993L15 3h5Z" />
                                </g>
                            </svg>
                        </button>
                        <button title="Minimize" x-show="fullscreen" x-on:click="makeFullscreen">
                            <svg class="w-5 h-5 opacity-30 hover:opacity-100" viewBox="0 0 24 24"
                                xmlns="http://www.w3.org/2000/svg">
                                <path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"
                                    stroke-width="2" d="M6 14h4m0 0v4m0-4l-6 6m14-10h-4m0 0V6m0 4l6-6" />
                            </svg>
                        </button>
                    </div>
                </div>
                @if ($outputs)
                    <div id="logs" class="font-mono text-sm">
                        <!-- Simple View (Original) -->
                        <div x-show="useSimpleView">
                            <pre class="whitespace-pre-wrap break-all">{{ $outputs }}</pre>
                        </div>

                        <!-- Enhanced View (Current with color coding) -->
                        <div x-show="!useSimpleView">
                            @foreach (explode("\n", trim($outputs)) as $line)
                                @if (!empty(trim($line)))
                                    @php
                                        $lowerLine = strtolower($line);
                                        $isError =
                                            str_contains($lowerLine, 'error') ||
                                            str_contains($lowerLine, 'err') ||
                                            str_contains($lowerLine, 'failed') ||
                                            str_contains($lowerLine, 'exception');
                                        $isWarning =
                                            str_contains($lowerLine, 'warn') ||
                                            str_contains($lowerLine, 'warning') ||
                                            str_contains($lowerLine, 'wrn');
                                        $isDebug =
                                            str_contains($lowerLine, 'debug') ||
                                            str_contains($lowerLine, 'dbg') ||
                                            str_contains($lowerLine, 'trace');
                                        $barColor = $isError
                                            ? 'bg-red-500 dark:bg-red-400'
                                            : ($isWarning
                                                ? 'bg-warning-500 dark:bg-warning-400'
                                                : ($isDebug
                                                    ? 'bg-purple-500 dark:bg-purple-400'
                                                    : 'bg-blue-500 dark:bg-blue-400'));
                                        $bgColor = $isError
                                            ? 'bg-red-50/50 dark:bg-red-900/20 hover:bg-red-100/50 dark:hover:bg-red-800/30'
                                            : ($isWarning
                                                ? 'bg-warning-50/50 dark:bg-warning-900/20 hover:bg-warning-100/50 dark:hover:bg-warning-800/30'
                                                : ($isDebug
                                                    ? 'bg-purple-50/50 dark:bg-purple-900/20 hover:bg-purple-100/50 dark:hover:bg-purple-800/30'
                                                    : 'bg-blue-50/50 dark:bg-blue-900/20 hover:bg-blue-100/50 dark:hover:bg-blue-800/30'));

                                        // Check for timestamp at the beginning (ISO 8601 format)
                                        $timestampPattern = '/^(\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(?:\.\d{3})?Z?)\s+/';
                                        $hasTimestamp = preg_match($timestampPattern, $line, $matches);
                                        $timestamp = $hasTimestamp ? $matches[1] : null;
                                        $logContent = $hasTimestamp ? preg_replace($timestampPattern, '', $line) : $line;
                                    @endphp
                                    <div class="flex items-start gap-2 py-1 px-2 rounded-sm">
                                        <div class="w-1 {{ $barColor }} rounded-full flex-shrink-0 self-stretch"></div>
                                        <div class="flex-1 {{ $bgColor }} py-1 px-2 -mx-2 rounded-sm">
                                            @if ($hasTimestamp)
                                                <span
                                                    class="text-xs text-gray-500 dark:text-gray-400 font-mono mr-2">{{ $timestamp }}</span>
                                                <span class="whitespace-pre-wrap break-all">{{ $logContent }}</span>
                                            @else
                                                <span class="whitespace-pre-wrap break-all">{{ $line }}</span>
                                            @endif
                                        </div>
                                    </div>
                                @endif
                            @endforeach
                        </div>
                    </div>
                @else
                    <div id="logs" class="font-mono text-sm py-4 px-2 text-gray-500 dark:text-gray-400">
                        Refresh to get the logs...
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>