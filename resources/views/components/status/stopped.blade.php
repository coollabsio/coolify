@props([
    'status' => 'Stopped',
    'noLoading' => false,
])
@php
    if (str($status)->contains('(')) {
        $displayStatus = str($status)->before('(')->trim()->headline()->value();
    } elseif (str($status)->contains(':')) {
        $displayStatus = str(explode(':', $status)[0])->headline()->value();
    } else {
        $displayStatus = str($status)->headline()->value();
    }
@endphp
<x-status-badge status="{{ $displayStatus }}" type="error" />
