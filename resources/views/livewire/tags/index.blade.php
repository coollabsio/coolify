<div>
    <h1>Tags</h1>
    <div>Here you can see all the tags here</div>
    <div class="flex gap-2 pt-10 flex-wrap">
        @forelse ($tags as $tag)
            <a class="box" href="{{ route('tags.show', ['tag_name' => $tag->name]) }}">{{ $tag->name }}</a>
        @empty
            <div>No tags yet defined yet. Go to a resource and add a tag there.</div>
        @endforelse
    </div>
</div>
