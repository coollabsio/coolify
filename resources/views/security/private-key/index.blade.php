<x-layout>
    <x-security.navbar />
    <div class="flex gap-2">
        <h2 class="pb-4">Private Keys</h2>
        <a  href="{{ route('security.private-key.create') }}"><x-forms.button>+ Add</x-forms.button></a>
    </div>
    <div class="grid gap-2 lg:grid-cols-2">
        @forelse ($privateKeys as $key)
            <a class="text-center hover:no-underline box group"
                href="{{ route('security.private-key.show', ['private_key_uuid' => data_get($key, 'uuid')]) }}">
                <div class="group-hover:text-white">
                    <div>{{ $key->name }}</div>
                </div>
            </a>
        @empty
            <div>
                <div>No private keys found.</div>
                <x-use-magic-bar link="/security/private-key/new" />
            </div>
        @endforelse
    </div>
</x-layout>
