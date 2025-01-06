@extends('layouts.base')

<div class="flex flex-col items-center justify-center min-h-screen px-4 py-16 sm:px-6 sm:py-24">
    <div class="text-center">
        <p class="font-mono font-bold text-red-500 text-8xl sm:text-9xl">500</p>
        <h1 class="mt-6 text-2xl font-bold tracking-tight text-gray-900 dark:text-white sm:text-4xl">Wait, this is not cool...</h1>
        @if ($exception->getMessage() !== '')
            <div class="max-w-2xl mx-auto mt-8 text-sm text-red-500 whitespace-pre-wrap">
                Error: {!! $exception->getMessage() !!}
            </div>
        @endif
        <div class="flex items-center justify-center mt-10 gap-x-6">
            <a href="/" class="inline-block">
                <x-forms.button>Go back home</x-forms.button>
            </a>
            <a target="_blank" href="{{ config('constants.urls.contact') }}" class="inline-flex items-center text-sm text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white">
                Contact support
                <x-external-link class="ml-1 w-4 h-4" />
            </a>
        </div>
    </div>
</div>
