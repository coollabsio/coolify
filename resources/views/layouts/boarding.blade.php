@extends('layouts.base')
@section('body')
    <main class="h-full">
        {{ $slot }}
    </main>
    @parent
@endsection
