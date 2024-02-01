<div>
    <h1>Tag: {{ $tag->name }}</h1>
    <div class="">Tag details</div>
    <div class="lg:w-[500px] pt-4">
        <x-forms.input  readonly label="Tag Deploy Webhook URL" id="webhook"  />
    </div>
    <div class="pt-4">
        <div class="flex items-end gap-2">
            <h3>Resources</h3>
            <x-forms.button>Redeploy All</x-forms.button>
        </div>
        <div class="grid gap-2 pt-4 lg:grid-cols-2">
            @foreach ($resources as $resource)
                <div class="box">{{ data_get($resource, 'name') }}</div>
            @endforeach
        </div>
    </div>
</div>
