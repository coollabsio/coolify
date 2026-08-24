@extends('layouts.base')
@section('body')
    {{-- Manual @livewireScripts disables Livewire's asset auto-injection, so the
         styles (e.g. [wire\:loading] { display: none }) must be rendered manually too. --}}
    @livewireStyles
    @livewireScripts
    <main class="h-full bg-gray-50 dark:bg-base">
        {{ $slot }}
    </main>
    @parent
@endsection
