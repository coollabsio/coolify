<div>
    @forelse ($secrets as $secret)
        {{ dump($secret) }}
    @empty
        <p>There are no secrets for this application.</p>
    @endforelse
</div>
