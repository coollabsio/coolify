@extends('layouts.base')

@section('body')
    <body class="error-page-body text-black dark:text-inherit">
        <x-toast />
        <x-error-page
            code="403"
            title="You shall not pass!"
            description="You don't have permission to access this page." />
    </body>
@endsection
