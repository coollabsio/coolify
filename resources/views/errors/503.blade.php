@extends('layouts.base')

@section('body')
    <body class="error-page-body text-black dark:text-inherit">
        <x-toast />
        <x-error-page
            code="503"
            title="We are working on serious things."
            description="Service unavailable. Be right back. Thanks for your patience." />
    </body>
@endsection
