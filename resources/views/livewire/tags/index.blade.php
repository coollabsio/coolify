<div>
    <h1>Tags</h1>
    <div class="flex flex-col gap-2 pb-6 ">
        <div>Available tags: </div>
        <div class="flex flex-wrap gap-2">
            @forelse ($tags as $oneTag)
                <a class="flex items-center justify-center h-6 px-2 text-white min-w-14 w-fit hover:no-underline hover:bg-coolgray-200 bg-coolgray-100"
                    href="{{ route('tags.show', ['tag_name' => $oneTag->name]) }}">{{ $oneTag->name }}</a>
            @empty
                <div>No tags yet defined yet. Go to a resource and add a tag there.</div>
            @endforelse
        </div>
    </div>
</div>
