@extends('layouts.base')
@section('body')
    @parent
    <x-navbar />
    @persist('magic-bar')
        <div class="fixed z-30 top-[4.5rem] left-4" id="vue">
            <magic-bar></magic-bar>
        </div>
    @endpersist
    <livewire:layout-popups />
    @auth
        <livewire:realtime-connection />
    @endauth
    <main class="pb-10 main">
        {{ $slot }}
    </main>
@endsection
