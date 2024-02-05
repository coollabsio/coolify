@extends('layouts.base')
@section('body')
    @if (isSubscriptionActive() || isDev())
        <div title="Send us feedback or get help!" class="fixed top-0 right-0 p-2 px-4 pt-4 mt-auto text-xs">
            <button class="flex items-center justify-center gap-2" wire:click="help" onclick="help.showModal()">
                <svg class="w-5 h-5" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                    <path fill="currentColor"
                        d="M22 5.5H9c-1.1 0-2 .9-2 2v9a2 2 0 0 0 2 2h13c1.11 0 2-.89 2-2v-9a2 2 0 0 0-2-2m0 11H9V9.17l6.5 3.33L22 9.17v7.33m-6.5-5.69L9 7.5h13l-6.5 3.31M5 16.5c0 .17.03.33.05.5H1c-.552 0-1-.45-1-1s.448-1 1-1h4v1.5M3 7h2.05c-.02.17-.05.33-.05.5V9H3c-.55 0-1-.45-1-1s.45-1 1-1m-2 5c0-.55.45-1 1-1h3v2H2c-.55 0-1-.45-1-1Z" />
                </svg>
                Feedback
            </button>
        </div>
    @endif
    <main class="min-h-screen hero">
        <div class="hero-content">
            {{ $slot }}
        </div>
    </main>
    @parent
@endsection
