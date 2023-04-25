<div>
    @forelse ($envs as $env)
        {{ dump($env) }}
    @empty
        <p>There are no environment variables for this application.</p>
    @endforelse
</div>
