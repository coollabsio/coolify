@extends('proxy.layout')

@if (($status ?? 404) === 503)
    @section('title', 'Site not available')

    @section('content')
        <p class="status" aria-label="503 Service Unavailable">503</p>
        <h1>This site is not available at the moment</h1>
        <p><span id="host">This site</span> cannot answer your request right now. Please try again later.</p>
    @endsection
@else
    @section('title', 'Site not found')

    @section('content')
        <p class="status" aria-label="404 Not Found">404</p>
        <h1>There is no site at this address</h1>
        <p><span id="host">This site</span> is not configured on this server.</p>
    @endsection
@endif
