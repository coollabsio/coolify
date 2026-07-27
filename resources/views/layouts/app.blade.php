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
        }" x-cloak class="dark:text-inherit text-black">
            <livewire:deployments-indicator />

            {{-- ============ DESKTOP TOP BAR ============ --}}
            <header
                class="hidden lg:flex fixed top-0 inset-x-0 z-50 h-12 items-center bg-white/95 dark:bg-panel/95 backdrop-blur border-b border-neutral-200 dark:border-white/[0.06]">
                {{-- Brand (width tracks sidebar) --}}
                <div class="flex items-center gap-2 h-full shrink-0 border-r border-neutral-200 dark:border-white/[0.06] transition-[width] duration-200"
                    :class="collapsed ? 'w-16 justify-center px-0' : 'w-56 px-4'">
                    <a href="/" {{ wireNavigate() }} title="Coolify"
                        class="flex shrink-0 items-baseline gap-1.5 min-w-0 hover:opacity-80 transition-opacity">
                        <span x-show="collapsed" x-cloak class="text-[15px] font-semibold tracking-tight text-black dark:text-white">C</span>
                        <span x-show="!collapsed" class="text-[15px] font-semibold tracking-tight text-black dark:text-white">Coolify</span>
                        <span x-show="!collapsed" class="text-[10.5px] font-medium text-neutral-400 dark:text-fg-faint">v{{ config('constants.coolify.version') }}</span>
                    </a>
                    @if (isInstanceAdmin() && !isCloud())
                        <div x-show="!collapsed" class="ml-auto shrink-0">
                            @persist('upgrade')
                                <livewire:upgrade />
                            @endpersist
                        </div>
                    @endif
                </div>
                {{-- Collapse toggle + team switcher --}}
                <div class="flex items-center gap-1.5 min-w-0 flex-1 pl-3 pr-4">
                    <x-top-breadcrumb />
                    <div id="server-topbar-context" class="min-w-0"></div>
                    <div class="flex-1"></div>
                    {{-- Right cluster --}}
                    <x-top-user-menu />
                </div>
            </header>

            {{-- ============ MOBILE SLIDE-OVER SIDEBAR ============ --}}
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
                        <div class="flex flex-col pb-2 overflow-y-auto min-w-56 dark:bg-panel gap-y-5 scrollbar min-w-0">
                            <x-navbar />
                        </div>
                    </div>
                </div>
            </div>

            {{-- ============ DESKTOP SIDEBAR (below top bar) ============ --}}
            <div class="hidden lg:fixed lg:top-12 lg:bottom-0 lg:left-0 lg:z-40 lg:flex lg:flex-col min-w-0"
                :class="[collapsed ? 'lg:w-16' : 'lg:w-56', sidebarReady ? 'transition-[width] duration-200' : '']">
                <div class="flex flex-col overflow-y-auto grow scrollbar min-w-0">
                    <x-navbar />
                </div>
            </div>

            {{-- ============ MOBILE TOP BAR ============ --}}
            <div
                class="sticky top-0 z-40 flex items-center justify-between px-4 py-3 gap-x-4 sm:px-6 lg:hidden bg-white/95 dark:bg-panel/95 backdrop-blur-sm border-b border-neutral-200/60 dark:border-white/[0.06]">
                <div class="flex items-center gap-2.5 flex-shrink-0 min-w-0">
                    <a href="/" class="flex items-center justify-center size-8 rounded-lg bg-neutral-100 dark:bg-white/[0.06] hover:opacity-80 transition-opacity">
                        <img src="/coolify-logo.svg" alt="Coolify" class="w-[18px] h-[18px]" />
                    </a>
                    <div class="min-w-0" x-data="{ collapsed: false }">
                        <livewire:switch-team />
                    </div>
                </div>
                <div class="flex items-center gap-1">
                    <x-top-user-menu />
                    <button type="button" class="-m-1 p-2 text-neutral-500 dark:text-fg-dim" x-on:click="open = !open">
                        <span class="sr-only">Open sidebar</span>
                        <svg class="w-6 h-6" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
                            <path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"
                                stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                        </svg>
                    </button>
                </div>
            </div>

            {{-- ============ MAIN ============ --}}
            <main
                class="min-h-screen bg-white dark:bg-panel lg:border-l border-neutral-200 dark:border-white/[0.06] px-5 py-6 sm:px-8 lg:px-10 lg:pt-[calc(3rem+1.75rem)] lg:pb-10"
                :class="[collapsed ? 'lg:ml-16' : 'lg:ml-56', sidebarReady ? 'transition-[margin] duration-200' : '']">
                <div class="mx-auto w-full max-w-[1400px]">
                    {{ $slot }}
                </div>
            </main>
        </div>
    @endauth
@endsection
