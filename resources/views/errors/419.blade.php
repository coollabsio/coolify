@extends('layouts.base')
<div class="flex flex-col items-center justify-center h-full">
    <div>
        <p class="font-mono font-semibold text-7xl dark:text-warning">419</p>
        <h1 class="mt-4 font-bold tracking-tight dark:text-white">This page is definitely old, not like you!</h1>
        <p class="text-base leading-7 dark:text-neutral-300 text-black">Sorry, we couldn't find the page you're looking
            for.
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
