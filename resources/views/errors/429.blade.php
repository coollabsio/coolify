@extends('layouts.base')
<div class="min-h-screen hero">
    <div class="text-center hero-content">
        <div class="">
            <p class="font-mono text-6xl font-semibold text-warning">429</p>
            <h1 class="mt-4 font-bold tracking-tight text-white">Woah, slow down there!</h1>
            <p class="mt-6 text-base leading-7 text-neutral-300">You're making too many requests. Please wait a few
                seconds before trying again.
            </p>
            <div class="flex items-center justify-center mt-10 gap-x-6">
                <a href="/">
                    <x-forms.button>Go back home</x-forms.button>
                </a>
                <a href="{{ config('coolify.contact') }}" class="font-semibold text-white ">Contact
                    support
                    <span aria-hidden="true">&rarr;</span></a>
            </div>
        </div>
    </div>
</div>
