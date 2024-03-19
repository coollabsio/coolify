@extends('layouts.base')
@section('body')
    @parent

    <livewire:layout-popups />
    @auth
        <livewire:realtime-connection />
    @endauth
    <main class="flex gap-2">
        <x-navbar />
        <div class="w-full px-10 pt-4">
            {{ $slot }}
        </div>
    </main>
@endsection
