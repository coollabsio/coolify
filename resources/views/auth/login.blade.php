<x-layout>
    <div>
        <form action="/login" method="POST">
            @csrf
            <input type="text" name="email" placeholder="email" @env('local') value="test@example.com" @endenv
                autofocus />
            <input type="password" name="password" placeho  lder="Password" @env('local') value="password" @endenv />
            <button type="submit">Login</button>
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
    </div>
</x-layout>
