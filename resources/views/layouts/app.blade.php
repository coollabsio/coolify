@extends('layouts.base')
@section('body')
    @parent
    <x-navbar />
    @persist('magic-bar')
        <div class="fixed z-30 top-[4.5rem] left-4" id="vue">
            <magic-bar></magic-bar>
        </div>
    @endpersist
    <livewire:sponsorship />
    <main class="pb-10 main max-w-screen-2xl">
        {{ $slot }}
    </main>
@endsection
