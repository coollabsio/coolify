@extends('layouts.base')
@section('body')
    @parent
    <x-navbar />
    <div class="fixed top-3 left-4 z-50" id="vue">
        <magic-bar></magic-bar>
    </div>
    <main class="main max-w-screen-2xl">
        {{ $slot }}
    </main>
@endsection
