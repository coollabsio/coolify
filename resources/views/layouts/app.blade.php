@extends('layouts.base')
@section('body')
    @parent
    @if (isSubscribed() || !isCloud())
        <livewire:layout-popups />
    @endif
    @auth
        <div x-data="{
            open: false,
            init() {
                this.pageWidth = localStorage.getItem('pageWidth');
            }
        }" x-cloak class="mx-auto" :class="pageWidth === 'full' ? '' : 'max-w-7xl'">
            <div class="relative z-50 lg:hidden" :class="open ? 'block' : 'hidden'" role="dialog" aria-modal="true">
                <div class="fixed inset-0 bg-black/80"></div>
                <div class="fixed inset-0 flex">
                    <div class="relative flex flex-1 w-full mr-16 max-w-48 ">
                        <div class="absolute top-0 flex justify-center w-16 pt-5 left-full">
                            <button type="button" class="-m-2.5 p-2.5" x-on:click="open = !open">
                                <span class="sr-only">Close sidebar</span>
                                <svg class="w-6 h-6 text-white" fill="none" viewBox="0 0 24 24" stroke-width="1.5"
                                    stroke="currentColor" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>

                        <div class="flex flex-col pb-2 overflow-y-auto min-w-48 dark:bg-coolgray-100 gap-y-5 scrollbar">
                            <x-navbar />
                        </div>
                    </div>
                </div>
            </div>

            <div class="hidden lg:fixed lg:inset-y-0 lg:z-50 lg:flex lg:w-48 lg:flex-col">
                <div class="flex flex-col overflow-y-auto grow gap-y-5 scrollbar">
                    <x-navbar />
                </div>
            </div>

            <div class="sticky top-0 z-40 flex items-center px-4 py-4 gap-x-6 sm:px-6 lg:hidden">
                <button type="button" class="-m-2.5 p-2.5 dark:text-warning lg:hidden" x-on:click="open = !open">
                    <span class="sr-only">Open sidebar</span>
                    <svg class="w-6 h-6" xmlns="http://www.w3.org/2000/svg" width="200" height="200" viewBox="0 0 24 24">
                        <path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"
                            stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                    </svg>
                </button>
            </div>

            <main class="lg:pl-48">
                <div class="p-4 sm:px-6 lg:px-8 lg:py-6">
                    {{ $slot }}
                </div>
            </main>
        </div>
    @endauth
@endsection
