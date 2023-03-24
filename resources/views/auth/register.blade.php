<x-layout>
    <form action="/register" method="POST">
        @csrf
        <input type="text" name="name" placeholder="name" @env('local') value="Andras Bacsai" @endenv />
        <input type="text" name="email" placeholder="email" @env('local') value="andras@bacsai.com" @endenv />
        <input type="password" name="password" placeholder="Password" @env('local') value="password" @endenv />
        <input type="password" name="password_confirmation" placeholder="Password"
            @env('local') value="password" @endenv />
        <button type="submit">Register</button>
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
