@extends('layouts.base')
<div class="flex flex-col items-center justify-center h-full">
    <div>
        <p class="font-mono font-semibold text-7xl dark:text-warning">503</p>
        <h1 class="mt-4 font-bold tracking-tight dark:text-white">We are working on serious things.</h1>
        <p class="text-base leading-7 dark:text-neutral-400 text-black">Service Unavailable. Be right back. Thanks for
            your
            patience.
        </p>
        <div class="flex items-center mt-10 gap-x-2">
            <a href="{{ url()->previous() }}">
                <x-forms.button>Go back</x-forms.button>
            </a>
            <a href="{{ route('dashboard') }}" {{ wireNavigate() }}>
                <x-forms.button>Dashboard</x-forms.button>
            </a>
            <a target="_blank" class="text-xs" href="{{ config('constants.urls.contact') }}">Contact
                support
                <x-external-link />
            </a>
        </div>
    </div>
</div>
