@extends('layouts.base')
@section('body')
    @parent
    @if (isSubscribed() || !isCloud())
        <livewire:layout-popups />
    @endif
    <!-- Global search component - included once to prevent keyboard shortcut duplication -->
    <livewire:global-search />
    @auth
        <div x-data="{
            open: false,
            collapsed: localStorage.getItem('sidebarCollapsed') === 'true',
            pageWidth: localStorage.getItem('pageWidth') || 'full',
            sidebarReady: false,
            init() {
                if (!localStorage.getItem('pageWidth')) {
                    localStorage.setItem('pageWidth', this.pageWidth);
                }

                this.$nextTick(() => {
                    requestAnimationFrame(() => {
                        this.sidebarReady = true;
                    });
                });
            },
            toggleSidebar() {
                this.collapsed = !this.collapsed;
                localStorage.setItem('sidebarCollapsed', this.collapsed);
            }
        }" x-cloak class="mx-auto dark:text-inherit text-black"
            :class="pageWidth === 'full' ? '' : 'max-w-7xl'">
            <livewire:deployments-indicator />
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

            <div class="hidden lg:fixed lg:inset-y-0 lg:z-50 lg:flex lg:flex-col min-w-0"
                :class="[collapsed ? 'lg:w-16' : 'lg:w-56', sidebarReady ? 'transition-[width] duration-200' : '']">
                <div class="flex flex-col overflow-y-auto grow gap-y-5 scrollbar min-w-0">
                    <x-navbar />
                </div>
                <button type="button" @click="toggleSidebar()"
                    :title="collapsed ? 'Expand sidebar' : 'Collapse sidebar'"
                    class="absolute top-8 -right-3 z-50 hidden lg:flex items-center justify-center w-6 h-6 rounded-full border bg-white dark:bg-coolgray-100 dark:border-coolgray-200 border-neutral-300 hover:bg-neutral-100 dark:hover:bg-coolgray-200 transition-colors shadow-sm">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5 text-neutral-600 dark:text-neutral-300 transition-transform"
                        :class="collapsed ? '' : 'rotate-180'"
                        fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                    </svg>
                </button>
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

            <main class="p-6" :class="[collapsed ? 'lg:pl-[6rem]' : 'lg:pl-[16rem]', sidebarReady ? 'transition-[padding] duration-200' : '']">
                    {{ $slot }}
            </main>
        </div>
    @endauth
@endsection
