@extends('layouts.base')
@section('body')
    <div title="Send us feedback or get help!" class="fixed top-0 right-0 p-2 px-4 pt-4 mt-auto text-xs">
        <button class="flex items-center justify-center gap-2" wire:click="help" onclick="help.showModal()">
            <svg class="icon" viewBox="0 0 256 256" xmlns="http://www.w3.org/2000/svg">
                <path fill="currentColor"
                    d="M144 180a16 16 0 1 1-16-16a16 16 0 0 1 16 16m92-52A108 108 0 1 1 128 20a108.12 108.12 0 0 1 108 108m-24 0a84 84 0 1 0-84 84a84.09 84.09 0 0 0 84-84m-84-64c-24.26 0-44 17.94-44 40v4a12 12 0 0 0 24 0v-4c0-8.82 9-16 20-16s20 7.18 20 16s-9 16-20 16a12 12 0 0 0-12 12v8a12 12 0 0 0 23.73 2.56C158.31 137.88 172 122.37 172 104c0-22.06-19.74-40-44-40" />
            </svg>
        </button>
    </div>
    <main class="min-h-screen hero">
        <div class="hero-content">
            {{ $slot }}
        </div>
    </main>
    @parent
@endsection
