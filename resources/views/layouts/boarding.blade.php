@extends('layouts.base')
@section('body')
    <x-modal-input title="How can we help?">
        <x-slot:content>
            <div title="Send us feedback or get help!" class="cursor-pointer menu-item" wire:click="help"
                onclick="help.showModal()">
                <svg class="icon" viewBox="0 0 256 256" xmlns="http://www.w3.org/2000/svg">
                    <path fill="currentColor"
                        d="M140 180a12 12 0 1 1-12-12a12 12 0 0 1 12 12M128 72c-22.06 0-40 16.15-40 36v4a8 8 0 0 0 16 0v-4c0-11 10.77-20 24-20s24 9 24 20s-10.77 20-24 20a8 8 0 0 0-8 8v8a8 8 0 0 0 16 0v-.72c18.24-3.35 32-17.9 32-35.28c0-19.85-17.94-36-40-36m104 56A104 104 0 1 1 128 24a104.11 104.11 0 0 1 104 104m-16 0a88 88 0 1 0-88 88a88.1 88.1 0 0 0 88-88" />
                </svg>
                Feedback
            </div>
        </x-slot:content>
        <livewire:help />
    </x-modal-input>

    <main class="min-h-screen hero">
        <div class="hero-content">
            {{ $slot }}
        </div>
    </main>
    @parent
@endsection
