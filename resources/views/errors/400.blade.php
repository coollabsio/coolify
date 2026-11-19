@extends('layouts.base')

@section('body')
    <body class="error-page-body text-black dark:text-inherit">
        <x-toast />
        <x-error-page
            code="400"
            title="Bad request"
            :description="$exception->getMessage() ?: 'The request could not be understood by the server due to malformed syntax.'" />
    </body>
@endsection
