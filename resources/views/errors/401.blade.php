@extends('layouts.base')
<div class="min-h-screen hero">
    <div class="text-center hero-content">
        <div class="">
            <p class="font-mono text-6xl font-semibold text-warning">401</p>
            <h1 class="mt-4 font-bold tracking-tight dark:text-white">You shall not pass!</h1>
            <p class="mt-6 text-base leading-7 text-neutral-300">You don't have permission to access this page.
            </p>
            <div class="flex items-center justify-center mt-10 gap-x-6">
                <a href="/">
                    <x-forms.button>Go back home</x-forms.button>
                </a>
                <a target="_blank" class="text-xs" href="{{ config('coolify.contact') }}">Contact
                    support
                    <x-external-link />
                </a>
            </div>
        </div>
    </div>
</div>
