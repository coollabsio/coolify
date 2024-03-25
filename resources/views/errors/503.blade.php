@extends('layouts.base')
<div class="min-h-screen hero ">
    <div class="text-center hero-content">
        <div>
            <p class="font-mono text-6xl font-semibold dark:text-warning">503</p>
            <h1 class="mt-4 font-bold tracking-tight dark:text-white">We are working on serious things.</h1>
            <p class="mt-6 text-base leading-7 text-neutral-300">Service Unavailable. Be right back. Thanks for your
                patience.
            </p>
            <div class="flex items-center justify-center mt-10 gap-x-6">
                <a href="{{ config('coolify.contact') }}" class="font-semibold dark:text-white ">Contact
                    support
                    <span aria-hidden="true">&rarr;</span></a>
            </div>
        </div>
    </div>
</div>
