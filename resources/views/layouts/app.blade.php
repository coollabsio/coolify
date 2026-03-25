@extends('layouts.base')
@section('body')
    @parent
    @if (isSubscribed() || !isCloud())
        <livewire:layout-popups />
    @endif
    <!-- Global search component - included once to prevent keyboard shortcut duplication -->
    <livewire:global-search />
    @auth
        <livewire:deployments-indicator />
        @php
            $teamId = (string) data_get(auth()->user()?->currentTeam(), 'id', '0');
            $globalCompressionTasks = \Illuminate\Support\Facades\Cache::get("file-explorer-compression-tasks:{$teamId}", []);
            $globalCompressionTasks = is_array($globalCompressionTasks) ? $globalCompressionTasks : [];
        @endphp
        <div x-data="{
            open: false,
            init() {
                this.pageWidth = localStorage.getItem('pageWidth');
                if (!this.pageWidth) {
                    this.pageWidth = 'full';
                    localStorage.setItem('pageWidth', 'full');
                }
            }
        }" x-cloak class="mx-auto dark:text-inherit text-black"
            :class="pageWidth === 'full' ? '' : 'max-w-7xl'">
            <div class="relative z-50 lg:hidden" :class="open ? 'block' : 'hidden'" role="dialog" aria-modal="true">
                <div class="fixed inset-0 bg-black/80" x-on:click="open = false"></div>
                <div class="fixed inset-y-0 right-0 h-full flex">
                    <div class="relative flex flex-1 w-full max-w-56 min-w-0">
                        <div class="absolute top-0 flex justify-center w-16 pt-5 right-full">
                            <button type="button" class="-m-2.5 p-2.5" x-on:click="open = !open">
                                <span class="sr-only">Close sidebar</span>
                                <svg class="w-6 h-6 text-white" fill="none" viewBox="0 0 24 24" stroke-width="1.5"
                                    stroke="currentColor" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>

                        <div class="flex flex-col pb-2 overflow-y-auto min-w-56 dark:bg-coolgray-100 gap-y-5 scrollbar min-w-0">
                            <x-navbar />
                        </div>
                    </div>
                </div>
            </div>

            <div class="hidden lg:fixed lg:inset-y-0 lg:z-50 lg:flex lg:w-56 lg:flex-col min-w-0">
                <div class="flex flex-col overflow-y-auto grow gap-y-5 scrollbar min-w-0">
                    <x-navbar />
                </div>
            </div>

            <div
                class="sticky top-0 z-40 flex items-center justify-between px-4 py-4 gap-x-6 sm:px-6 lg:hidden bg-white/95 dark:bg-base/95 backdrop-blur-sm border-b border-neutral-300/50 dark:border-coolgray-200/50">
                <div class="flex items-center gap-3 flex-shrink-0">
                    <a href="/"
                        class="text-xl font-bold tracking-wide dark:text-white hover:opacity-80 transition-opacity">Coolify</a>
                    <livewire:switch-team />
                </div>
                <button type="button" class="-m-2.5 p-2.5 dark:text-warning" x-on:click="open = !open">
                    <span class="sr-only">Open sidebar</span>
                    <svg class="w-6 h-6" xmlns="http://www.w3.org/2000/svg" width="200" height="200" viewBox="0 0 24 24">
                        <path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"
                            stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                    </svg>
                </button>
            </div>

            <main class="lg:pl-56">
                <div class="p-4 sm:px-6 lg:px-8 lg:py-6">
                    <div class="sticky top-4 z-40 mb-4 flex justify-end">
                        <details class="relative group">
                            <summary class="list-none cursor-pointer rounded bg-coollabs px-3 py-2 text-xs font-semibold text-white shadow-lg hover:opacity-90">
                                Compression Tasks ({{ count($globalCompressionTasks) }})
                            </summary>
                            <div class="absolute right-0 mt-2 w-[30rem] max-h-[28rem] overflow-auto rounded border border-coolgray-300 dark:border-coolgray-600 bg-white dark:bg-coolgray-100 p-3 shadow-2xl">
                                <div class="mb-2 flex items-center justify-between">
                                    <h4 class="text-sm font-semibold dark:text-white">Background Compression Tasks</h4>
                                    <a href="{{ url()->current() }}" class="rounded bg-gray-700 px-2 py-1 text-[11px] text-white hover:opacity-90">Refresh</a>
                                </div>
                                @if (empty($globalCompressionTasks))
                                    <p class="text-xs text-gray-500 dark:text-gray-400">No compression tasks.</p>
                                @else
                                    <div class="space-y-2">
                                        @foreach ($globalCompressionTasks as $task)
                                            <div class="rounded border border-coolgray-300 dark:border-coolgray-600 p-2">
                                                <div class="flex items-center justify-between gap-2">
                                                    <p class="truncate text-xs font-semibold dark:text-white">{{ data_get($task, 'archive_name', 'archive.zip') }}</p>
                                                    <span class="text-[11px] {{ data_get($task, 'status') === 'completed' ? 'text-green-500' : (data_get($task, 'status') === 'failed' ? 'text-red-500' : 'text-yellow-500') }}">
                                                        {{ strtoupper((string) data_get($task, 'status', 'running')) }}
                                                    </span>
                                                </div>
                                                <p class="truncate text-[11px] text-gray-500 dark:text-gray-400">Dir: {{ data_get($task, 'directory', '/') }}</p>
                                                <p class="break-words text-[11px] text-gray-500 dark:text-gray-400">{{ data_get($task, 'last_message', '') }}</p>
                                                <div class="mt-2">
                                                    <a href="{{ data_get($task, 'open_url', '#') }}" class="rounded bg-blue-600 px-2 py-1 text-[11px] text-white hover:opacity-90">Open Location</a>
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        </details>
                    </div>
                    {{ $slot }}
                </div>
            </main>
        </div>
    @endauth
@endsection
