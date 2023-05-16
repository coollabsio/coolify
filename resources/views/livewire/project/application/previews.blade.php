<div>
    <h2>Previews</h2>
    <div class="flex gap-2">
        @foreach ($application->previews as $preview)
            <div class="box">{{ $preview['pullRequestId'] }} | {{ $preview['branch'] }}</div>
        @endforeach
    </div>
</div>
