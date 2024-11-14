@extends('layouts.base')
<div class="flex flex-col items-center justify-center h-full">
    <div>
        <p class="font-mono font-semibold text-7xl dark:text-warning">429</p>
        <h1 class="mt-4 font-bold tracking-tight dark:text-white">Woah, slow down there!</h1>
        <p class="text-base leading-7 text-neutral-300">You're making too many requests. Please wait a few
            seconds before trying again.
        </p>
        <div class="flex items-center mt-10 gap-x-6">
            <a href="/">
                <x-forms.button>Go back home</x-forms.button>
            </a>
            <a target="_blank" class="text-xs" href="{{ config('constants.urls.contact') }}">Contact
                support
                <x-external-link />
            </a>
        </div>
    </div>
</div>
