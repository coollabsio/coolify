<div>
    @if (! $containerRunning)
        <p>Start the container to browse its files.</p>
    @else
        <p>{{ $currentPath }}</p>
        <ul>
            @foreach ($entries as $entry)
                <li>{{ $entry['name'] }}</li>
            @endforeach
        </ul>
    @endif
</div>
