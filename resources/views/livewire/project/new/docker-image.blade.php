<div>
    <h1>Create a new Application</h1>
    <div class="pb-4">You can deploy an existing Docker Image from any Registry.</div>
    <form wire:submit="submit">
        <div class="flex gap-2 pt-4 pb-1">
            <h2>Docker Image</h2>
            <x-forms.button type="submit">Save</x-forms.button>
        </div>
        <x-forms.input required id="dockerImage" label="Image" placeholder="nginx:latest" />

        <div class="pt-4 w-fit">
            <x-forms.checkbox wire:model.live="useCustomRegistry" id="useCustomRegistry"
                helper="Select a registry to pull the image from." label="Use Private Registry" />
        </div>

        @if ($useCustomRegistry)
            <div class="pt-4">
                <x-forms.select multiple name="selectedRegistries" id="selectedRegistries"
                    wire:model="selectedRegistries" label="Select Registries" required>
                    @foreach ($registries as $registry)
                        <option {{ collect($selectedRegistries)->contains($registry->id) ? 'selected' : '' }}
                            value="{{ $registry->id }}">{{ $registry->name }}</option>
                    @endforeach
                </x-forms.select>
            </div>
        @endif
    </form>
</div>
