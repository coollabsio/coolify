<div>
    <h2>Tags</h2>
    @foreach ($this->resource->tags as $tag)
        <div>
            <div>{{ $tag->name }}</div>
            <x-forms.button isError wire:click="deleteTag('{{ $tag->id }}','{{ $tag->name }}')">Delete</x-forms.button>
        </div>
    @endforeach
    <form wire:submit='submit'>
        <x-forms.input label="Add/Assign a tag" wire:model="new_tag"  wire:confirm="Are you sure you want to delete this post?" />
        <x-forms.button type="submit">Add</x-forms.button>
    </form>
</div>
