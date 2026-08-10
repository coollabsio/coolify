@extends('layouts.base')

@section('body')
    <body class="error-page-body text-black dark:text-inherit">
        <x-toast />
        <x-error-page
            code="404"
            title="How did you get here?"
            description="Sorry, we couldn't find the page you're looking for." />
    </body>
@endsection
