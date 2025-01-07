@extends('layouts.base')
<div class="flex items-center justify-center min-h-screen">
    <div class="w-full max-w-3xl px-8">
        <p class="font-mono font-semibold text-red-500 text-[200px] leading-none">500</p>
        <h1 class="text-3xl font-bold tracking-tight dark:text-white">Wait, this is not cool...</h1>
        <p class="mt-2 text-lg leading-7 text-neutral-300">There has been an error with the following error message:</p>
        @if ($exception->getMessage() !== '')
            <div class="mt-6 text-sm text-red-500">
                {!! Purify::clean($exception->getMessage()) !!}
            </div>
        @endif
        <div class="flex items-center mt-10 gap-6">
            <a href="/">
                <x-forms.button>Go back home</x-forms.button>
            </a>
            <a target="_blank" class="text-sm hover:text-neutral-300 flex items-center gap-1" href="{{ config('constants.urls.contact') }}">
                Contact support
                <x-external-link />
            </a>
        </div>
    </div>
</div>
