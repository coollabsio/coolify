@extends('layouts.base')
@section('body')
    @livewireScripts
    <main class="h-full bg-gray-50 dark:bg-base">
        {{ $slot }}
    </main>
    @parent
@endsection
