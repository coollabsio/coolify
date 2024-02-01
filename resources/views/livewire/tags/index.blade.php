<div>
    <h1>Tags</h1>
    <div class="flex gap-2 pt-10">
        @forelse ($tags as $tag)
            <a class="box" href="{{ route('tags.show', ['tag_name' => $tag->name]) }}">{{ $tag->name }}</a>
        @empty
            <p>No tags yet</p>
        @endforelse
    </div>
</div>
