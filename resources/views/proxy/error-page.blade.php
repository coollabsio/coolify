@extends('proxy.layout')

@section('title', 'Site not available')

@section('content')
    <p class="status" aria-label="503 Service Unavailable">503</p>
    <h1>This site is not available at the moment</h1>
    <p><span id="host">This site</span> cannot answer your request right now. Please try again later.</p>
@endsection
