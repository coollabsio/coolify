@extends('layouts.base')
@section('body')
    @parent
    <main>
        {{ $slot }}
    </main>
@endsection
