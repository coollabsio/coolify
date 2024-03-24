<x-layout>
    <x-security.navbar />
    <div class="flex gap-2">
        <h2 class="pb-4">Private Keys</h2>
        <x-slide-over  closeWithX fullScreen>
            <x-slot:title>New Private Key</x-slot:title>
            <x-slot:content>
                <livewire:security.private-key.create />
            </x-slot:content>
            <button @click="slideOverOpen=true" class="button">+
                Add</button>
        </x-slide-over>
    </div>
    <div class="grid gap-2 lg:grid-cols-2">
        @forelse ($privateKeys as $key)
            <a class="text-center hover:no-underline box group"
                href="{{ route('security.private-key.show', ['private_key_uuid' => data_get($key, 'uuid')]) }}">
                <div class="group-hover:dark:text-white">
                    <div>{{ $key->name }}</div>
                </div>
            </a>
        @empty
            <div>No private keys found.</div>
        @endforelse
    </div>
</x-layout>
