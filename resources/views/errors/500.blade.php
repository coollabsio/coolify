@extends('layouts.base')
<div class="flex items-center justify-center min-h-screen">
    <div class="w-full max-w-3xl px-8">
        <p class="font-mono font-semibold text-red-500 text-[200px] leading-none">500</p>
        <h1 class="text-3xl font-bold tracking-tight dark:text-white">{{ __('error.500.title') }}</h1>
        <p class="mt-2 text-lg leading-7 dark:text-neutral-400 text-black">{{ __('error.500.body') }}</p>
        @if ($exception->getMessage() !== '')
            <div class="mt-6 text-sm text-red-500">
                {!! Purify::clean($exception->getMessage()) !!}
            </div>
        @endif
        <div class="flex items-center mt-10 gap-x-2">
            <a href="{{ url()->previous() }}">
                <x-forms.button>{{ __('error.back') }}</x-forms.button>
            </a>
            <a href="{{ route('dashboard') }}" {{ wireNavigate() }}>
                <x-forms.button>{{ __('error.dashboard') }}</x-forms.button>
            </a>
            <a target="_blank" class="text-xs" href="{{ config('constants.urls.contact') }}">{{ __('error.contact_support') }}
                <x-external-link />
            </a>
        </div>
    </div>
</div>
