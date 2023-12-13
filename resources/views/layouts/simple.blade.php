@extends('layouts.base')
@section('body')
    @parent
    <x-navbar-subscription />
    <main>
        {{ $slot }}
    </main>
@endsection
