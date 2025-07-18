@extends('layouts.base')
<div class="flex flex-col items-center justify-center h-full">
    <div>
        <p class="font-mono font-semibold text-7xl dark:text-warning">503</p>
        <h1 class="mt-4 font-bold tracking-tight dark:text-white">We are working on serious things.</h1>
        <p class="text-base leading-7 text-black dark:text-neutral-300">Service Unavailable. Be right back. Thanks for your
            patience.
        </p>
        <div class="flex items-center mt-10 gap-x-6">
            <a target="_blank" class="text-xs" href="{{ config('constants.urls.contact') }}">Contact
                support
                <x-external-link />
            </a>
        </div>
    </div>
</div>
