@extends('layouts.base')
<div class="flex flex-col items-center justify-center h-full">
    <div>
        <p class="font-mono font-semibold text-red-500 text-7xl">500</p>
        <h1 class="mt-4 font-bold tracking-tight dark:text-white">Wait, this is not cool...</h1>
        <p class="text-base leading-7 text-neutral-300">There has been an error, we are working on it.
        </p>
        @if ($exception->getMessage() !== '')
            <code class="mt-6 text-xs text-left text-red-500">Error: {{ $exception->getMessage() }}
            </code>
        @endif
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
