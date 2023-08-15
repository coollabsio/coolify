<x-layout>
    <h1>Private Keys</h1>
    <div class="subtitle ">All Private Keys</div>
    <div class="grid gap-2 lg:grid-cols-2">
        @forelse ($privateKeys as $key)
            <a class="text-center hover:no-underline box group"
                href="{{ route('private-key.show', ['private_key_uuid' => data_get($key, 'uuid')]) }}">
                <div class="group-hover:text-white">
                    <div>{{ $key->name }}</div>
                </div>
            </a>
        @empty
            <div>
                <div>No private keys found.</div>
                <x-use-magic-bar link="/private-key/new" />
            </div>
        @endforelse
    </div>
</x-layout>
