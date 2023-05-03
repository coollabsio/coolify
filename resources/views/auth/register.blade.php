<x-layout>
    <div>v{{ config('coolify.version') }}</div>
    <a href="/login">Login</a>
    <a href="/register">Register</a>
    <form action="/register" method="POST">
        @csrf
        <input type="text" name="name" placeholder="name" @env('local') value="Root" @endenv />
        <input type="text" name="email" placeholder="email" @env('local') value="test@example.com" @endenv />
        <input type="password" name="password" placeholder="Password" @env('local') value="password" @endenv />
        <input type="password" name="password_confirmation" placeholder="Password"
            @env('local') value="password" @endenv />
        <x-inputs.button type="submit">Register</x-inputs.button>
    </form>
    @if ($errors->any())
        <div class="alert alert-danger">
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif
</x-layout>
